import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../api';
import { norm, TIPOS } from '../logic';
import Tag from './Tag';
import Paginacion from './Paginacion';
import SelectorCliente from './SelectorCliente';
import { usePagination } from '../hooks/usePagination';

const unir = (...v) => v.filter(Boolean).join(' · ');
const DIAS_SEMANA = [
  ['L', 'Lunes'], ['M', 'Martes'], ['X', 'Miércoles'], ['J', 'Jueves'],
  ['V', 'Viernes'], ['S', 'Sábado'], ['D', 'Domingo'],
];

function HorarioSemana({ equipo, onSaved }) {
  const horarioActual = /^[01]{7}$/.test(equipo.dias_copia || '') && equipo.dias_copia.includes('1')
    ? equipo.dias_copia : '1111111';
  const [dias, setDias] = useState(horarioActual);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState('');
  const [guardado, setGuardado] = useState(false);

  useEffect(() => {
    setDias(horarioActual);
    setError('');
  }, [equipo.id, horarioActual]);

  const cambiar = (indice, marcado) => {
    const seleccion = dias.split('');
    if (!marcado && seleccion.filter((dia) => dia === '1').length === 1) {
      setError('Selecciona al menos un día.');
      setGuardado(false);
      return;
    }
    seleccion[indice] = marcado ? '1' : '0';
    setDias(seleccion.join(''));
    setError('');
    setGuardado(false);
  };

  const guardar = async () => {
    setGuardando(true);
    setError('');
    setGuardado(false);
    try {
      await api('equipo_horario', { id: equipo.id, dias_copia: dias });
      onSaved(equipo.id, dias);
      setGuardado(true);
    } catch (e) {
      setError(e.message);
    } finally {
      setGuardando(false);
    }
  };

  return <div className="equipo-horario">
    <fieldset disabled={guardando}>
      <legend>Días esperados para las copias</legend>
      <div className="equipo-dias">
        {DIAS_SEMANA.map(([abreviatura, nombre], indice) => (
          <label className={dias[indice] === '1' ? 'seleccionado' : ''} key={nombre} title={nombre}>
            <input type="checkbox" aria-label={nombre} checked={dias[indice] === '1'}
              onChange={(e) => cambiar(indice, e.target.checked)} />
            <span aria-hidden="true">{abreviatura}</span>
          </label>
        ))}
      </div>
    </fieldset>
    <button className="btn sec equipo-horario-guardar" disabled={guardando || dias === horarioActual}
      onClick={guardar}>{guardando ? 'Guardando…' : 'Guardar días'}</button>
    <span className={`equipo-horario-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'} aria-live="polite">
      {error || (guardando ? 'Guardando horario…' : guardado ? 'Horario guardado.' : '')}
    </span>
  </div>;
}

function sugerirCliente(clientes, sugerido) {
  const s = norm(String(sugerido).trim());
  if (!s) return undefined;
  const exacto = clientes.find((c) => [c.nombre, c.codigo, unir(c.nombre, c.codigo)].some((v) => v && norm(String(v)) === s));
  if (exacto) return exacto;
  if (s.length < 4) return undefined;
  const parciales = clientes.filter((c) => {
    const n = norm(String(c.nombre || ''));
    return n.length >= 4 && (n.includes(s) || s.includes(n));
  });
  return parciales.length === 1 ? parciales[0] : undefined;
}

export default function Clientes({ sesion, refreshSignal = 0, clienteId = null, equipoId = null, onNavigate }) {
  const admin = sesion?.rol === 'admin';
  const [cat, setCat] = useState(null);
  const [equipos, setEquipos] = useState([]);
  const clienteAbierto = clienteId;
  const equipoAbierto = equipoId;
  const [busqueda, setBusqueda] = useState('');
  const [asignaciones, setAsignaciones] = useState({});
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [ultimaLectura, setUltimaLectura] = useState(null);
  const [fallo, setFallo] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(null);
  const [historial, setHistorial] = useState([]);
  const [historialFallo, setHistorialFallo] = useState('');
  const [cargandoHistorial, setCargandoHistorial] = useState(false);
  const [cargandoMas, setCargandoMas] = useState(false);
  const [hayMasHistorial, setHayMasHistorial] = useState(false);
  const cargaActual = useRef(0);
  const cargaHistorialActual = useRef(0);
  const equiposActuales = useRef([]);
  const sincronizando = useRef(false);

  const cargar = useCallback(async (silencioso = false, duranteSync = false) => {
    if (sincronizando.current && !duranteSync) return { error: '', actualizado: false };
    const carga = ++cargaActual.current;
    if (!silencioso && !equiposActuales.current.length) setCargando(true);
    try {
      const [c, e] = await Promise.all([api('catalogo'), api('equipos')]);
      if (carga !== cargaActual.current) return { error: '', actualizado: false };
      if (!Array.isArray(e.equipos)) throw new Error('La respuesta de equipos no es válida.');
      if (equiposActuales.current.length && !e.equipos.length) {
        throw new Error('La actualización devolvió una lista vacía. Se mantienen los datos anteriores.');
      }
      setCat(c);
      equiposActuales.current = e.equipos;
      setEquipos(e.equipos);
      if (e.correo) setUltimaLectura(e.correo);
      setFallo('');
      return { error: '', actualizado: true };
    } catch (e) {
      if (carga !== cargaActual.current) return { error: '', actualizado: false };
      setFallo(e.message);
      return { error: e.message, actualizado: false };
    } finally {
      if (carga === cargaActual.current) setCargando(false);
    }
  }, []);

  const cargarHistorial = useCallback(async (equipoId, cursor = null, anexar = false) => {
    const carga = ++cargaHistorialActual.current;
    setHistorialFallo('');
    if (anexar) setCargandoMas(true);
    else setCargandoHistorial(true);
    try {
      const respuesta = await api('equipo_historial', {
        equipo_id: equipoId,
        ...(cursor ? { cursor_fecha: cursor.cursor_fecha, cursor_id: cursor.id } : {}),
      });
      if (carga !== cargaHistorialActual.current) return;
      setHistorial((actual) => anexar ? [...actual, ...respuesta.copias] : respuesta.copias);
      setHayMasHistorial(Boolean(respuesta.hay_mas_historial));
    } catch (e) {
      if (carga === cargaHistorialActual.current) setHistorialFallo(e.message);
    } finally {
      if (carga === cargaHistorialActual.current) {
        setCargandoHistorial(false);
        setCargandoMas(false);
      }
    }
  }, []);

  useEffect(() => {
    cargar();
    const timer = setInterval(() => cargar(true), 60000);
    return () => clearInterval(timer);
  }, [cargar]);

  useEffect(() => {
    if (refreshSignal > 0) {
      cargar(true);
      if (equipoAbierto !== null) cargarHistorial(equipoAbierto);
    }
  }, [refreshSignal, equipoAbierto, cargar, cargarHistorial]);

  useEffect(() => {
    if (equipoAbierto === null) {
      cargaHistorialActual.current += 1;
      setHistorial([]);
      setHistorialFallo('');
      setHayMasHistorial(false);
      return;
    }
    setHistorial([]);
    cargarHistorial(equipoAbierto);
  }, [equipoAbierto, cargarHistorial]);

  const clientes = cat?.clientes || [];
  const idsClientesActivos = new Set(clientes.map((cliente) => Number(cliente.id)));
  const equiposClienteInactivo = equipos.filter((equipo) => equipo.cliente_id && !idsClientesActivos.has(Number(equipo.cliente_id)));
  const clienteActual = clienteAbierto === 'sin-asignar'
    ? { nombre: 'Equipos sin asignar' }
    : clienteAbierto === 'cliente-inactivo'
      ? { nombre: 'Cliente no activo' }
    : clientes.find((cliente) => Number(cliente.id) === Number(clienteAbierto));
  const equipoActual = equipos.find((equipo) => Number(equipo.id) === Number(equipoAbierto));
  const equiposCliente = useMemo(() => {
    if (clienteAbierto === 'sin-asignar') return equipos.filter((equipo) => !equipo.cliente_id);
    if (clienteAbierto === 'cliente-inactivo') return equipos.filter((equipo) => equipo.cliente_id && !idsClientesActivos.has(Number(equipo.cliente_id)));
    if (clienteAbierto === null) return [];
    return equipos.filter((equipo) => Number(equipo.cliente_id) === Number(clienteAbierto));
  }, [equipos, clienteAbierto, cat]);
  const textoBusqueda = norm(busqueda.trim());
  const clientesFiltrados = useMemo(() => clientes.filter((cliente) => {
    const datos = unir(cliente.nombre, cliente.codigo);
    return !textoBusqueda || norm(datos).includes(textoBusqueda);
  }), [clientes, textoBusqueda]);
  const equiposFiltrados = useMemo(() => equiposCliente
    .filter((equipo) => !textoBusqueda || norm(unir(equipo.nombre, equipo.software, equipo.sugerido, equipo.origen)).includes(textoBusqueda))
    .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es')),
  [equiposCliente, textoBusqueda]);
  const paginaClientes = usePagination(clientesFiltrados, 24, textoBusqueda);
  const paginaEquipos = usePagination(equiposFiltrados, 24, `${clienteAbierto}|${textoBusqueda}`);
  const equiposSinAsignar = equipos.filter((equipo) => !equipo.cliente_id);
  const totalServidores = equiposCliente.filter((equipo) => equipo.tipo === 'servidor').length;
  const equipoSugerencia = equipoActual?.sugerido ? sugerirCliente(clientes, equipoActual.sugerido) : undefined;
  const clienteElegido = asignaciones[equipoActual?.id] ?? equipoActual?.cliente_id ?? equipoSugerencia?.id ?? '';
  const hayCambioAsignacion = Number(clienteElegido) > 0 && Number(clienteElegido) !== Number(equipoActual?.cliente_id || 0);
  const u = cat?.ultima;
  const hayProblemasEstado = Boolean(
    (ultimaLectura && (ultimaLectura.alerta || ultimaLectura.ok === 0)) || u?.ok === 0
  );
  const ultimaActividad = [ultimaLectura?.fecha, u?.fecha].filter(Boolean).sort().slice(-1)[0];

  const sincronizar = async () => {
    if (sincronizando.current) return;
    sincronizando.current = true;
    cargaActual.current += 1;
    setSync(true);
    setFallo('');
    setMensaje('');
    let falloSync = '';
    try { await api('sync', {}); }
    catch (e) { falloSync = e.message; }
    try {
      const resultado = await cargar(true, true);
      if (falloSync) setFallo(resultado.error ? `${falloSync}. No se pudieron actualizar los datos: ${resultado.error}` : falloSync);
      else if (resultado.actualizado) setMensaje('Lista de clientes actualizada.');
    } finally {
      sincronizando.current = false;
      setSync(false);
    }
  };

  const asignar = async () => {
    const cliente_id = Number(clienteElegido || 0);
    if (!equipoActual || !cliente_id) {
      setFallo(`Selecciona un cliente para ${equipoActual?.nombre || 'este equipo'}.`);
      return;
    }
    setGuardando(equipoActual.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_asignar', { id: equipoActual.id, cliente_id });
      onNavigate(`/clientes/${cliente_id}/equipos/${equipoActual.id}`);
      setMensaje(`Asignación guardada. ${r.copias_importadas} copia${r.copias_importadas === 1 ? '' : 's'} incorporada${r.copias_importadas === 1 ? '' : 's'}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const desasignar = async () => {
    if (!equipoActual) return;
    setGuardando(equipoActual.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_desasignar', { id: equipoActual.id });
      onNavigate(`/clientes/sin-asignar/equipos/${equipoActual.id}`);
      setMensaje(`Equipo desasignado. ${r.copias_desasignadas} copia${r.copias_desasignadas === 1 ? '' : 's'} actualizada${r.copias_desasignadas === 1 ? '' : 's'}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const vigilar = async (equipo, valor) => {
    setGuardando(equipo.id);
    setFallo('');
    try { await api('equipo_vigilar', { id: equipo.id, vigilar: valor }); await cargar(true); }
    catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const actualizarHorario = (id, dias) => {
    const actualizar = (lista) => lista.map((item) => item.id === id ? { ...item, dias_copia: dias } : item);
    cargaActual.current += 1;
    equiposActuales.current = actualizar(equiposActuales.current);
    setEquipos(actualizar);
  };

  const volverAClientes = () => {
    onNavigate('/clientes');
    setBusqueda('');
    setFallo('');
    setMensaje('');
  };
  const volverAEquipos = () => {
    onNavigate(`/clientes/${encodeURIComponent(String(clienteAbierto))}`);
    setBusqueda('');
    setFallo('');
    setMensaje('');
  };

  return <div className="clientes-page">
    <section className="mapeo-panel" aria-labelledby="titulo-mapeo">
      <div className="mapeo-heading">
        <div>
          <h2 id="titulo-mapeo">{equipoActual ? equipoActual.nombre : clienteActual ? clienteActual.nombre : 'Clientes'}</h2>
          {clienteActual && !equipoActual && <p>{equiposCliente.length} equipos · {totalServidores} servidores</p>}
          {equipoActual && <p>{equipoActual.software}{equipoActual.tipo ? ` · ${TIPOS[equipoActual.tipo]}` : ''}</p>}
        </div>
        <div className="mapeo-kpi" aria-label={`${equiposSinAsignar.length} equipos sin asignar`}>
          <strong>{equiposSinAsignar.length}</strong>
          <span>sin asignar</span>
        </div>
      </div>

      <div className="mapeo-toolbar">
        {clienteActual ? <button className="btn sec" onClick={equipoActual ? volverAEquipos : volverAClientes}>
          ← {equipoActual ? clienteActual.nombre : 'Clientes'}
        </button> : (
          <label className="mapeo-busqueda">
            <span className="sr-only">Buscar cliente</span>
            <input type="search" placeholder="Buscar cliente…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
          </label>
        )}
        {clienteActual && !equipoActual && <label className="mapeo-busqueda">
          <span className="sr-only">Buscar equipo</span>
          <input type="search" placeholder="Buscar equipo o software…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
        </label>}
        {admin && <button className="btn sec" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Actualizar clientes'}</button>}
      </div>

      {(ultimaLectura || u) && <details className={`estado-sistema${hayProblemasEstado ? ' con-problemas' : ''}`}>
        <summary>
          <strong>{hayProblemasEstado ? 'Revisar conexiones' : 'Conexiones al día'}</strong>
          {ultimaActividad && <span>Última actividad: {ultimaActividad}</span>}
        </summary>
        <div className="estado-sistema-detalle">
          <p><b>Recepción de copias:</b> {!ultimaLectura?.fecha ? 'sin avisos recibidos' : ultimaLectura.ok === 0 ? 'última recepción con errores' : 'operativa'}
            {ultimaLectura?.fecha && ` · ${ultimaLectura.fecha}`}</p>
          {ultimaLectura?.alerta && <p className="estado-sistema-error">No se reciben avisos desde hace {ultimaLectura.horas_desde ?? 'más de'} h.</p>}
          <p><b>Sincronización de clientes:</b> {!u?.fecha ? 'sin sincronizaciones' : u.ok === 0 ? 'última sincronización con errores' : 'operativa'}
            {u?.fecha && ` · ${u.fecha}`}</p>
          {u?.ok === 0 && u.mensaje && <p className="estado-sistema-error">{u.mensaje}</p>}
        </div>
      </details>}

      {fallo && <div className="errbox" role="alert">{fallo} <button className="btn sec" onClick={() => cargar()}>Reintentar</button></div>}
      {mensaje && <div className="okmsg" role="status">{mensaje}</div>}
      {sync && <p className="sync-estado" role="status" aria-live="polite">Actualizando los clientes.</p>}
      {admin && cat && !cat.configurado && <div className="errbox">La API de clientes no está configurada en <code>config/config.php</code>.</div>}

      {cargando && !equipos.length && !cat ? <div className="mapeo-vacio">Cargando clientes…</div> : (
        <>
          {!clienteActual && !equipoActual && <>
            {clientesFiltrados.length || equiposSinAsignar.length ? <div className="cliente-grid">
              {paginaClientes.items.map((cliente) => {
                const asignados = equipos.filter((equipo) => Number(equipo.cliente_id) === Number(cliente.id));
                const servidores = asignados.filter((equipo) => equipo.tipo === 'servidor').length;
                return <button type="button" className="cliente-tarjeta" key={cliente.id}
                  onClick={() => { onNavigate(`/clientes/${cliente.id}`); setBusqueda(''); }}>
                  <span className="cliente-tarjeta-flecha">›</span>
                  <strong>{cliente.nombre}</strong>
                  {cliente.codigo && <span className="cliente-codigo">{cliente.codigo}</span>}
                  <span className="cliente-conteo">{asignados.length} equipos · {servidores} servidores</span>
                </button>;
              })}
              {equiposSinAsignar.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
                onClick={() => { onNavigate('/clientes/sin-asignar'); setBusqueda(''); }}>
                <span className="cliente-tarjeta-flecha">›</span>
                <strong>Sin asignar</strong>
                <span className="cliente-conteo">{equiposSinAsignar.length} equipos por vincular</span>
              </button>}
              {equiposClienteInactivo.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
                onClick={() => { onNavigate('/clientes/cliente-inactivo'); setBusqueda(''); }}>
                <span className="cliente-tarjeta-flecha">›</span>
                <strong>Cliente no activo</strong>
                <span className="cliente-conteo">{equiposClienteInactivo.length} equipos conservados</span>
              </button>}
            </div> : <div className="mapeo-vacio">No hay clientes ni equipos para mostrar.</div>}
            <Paginacion total={clientesFiltrados.length} pagina={paginaClientes.page} paginas={paginaClientes.pageCount}
              porPagina={paginaClientes.pageSize} onCambiar={paginaClientes.setPage} etiqueta="clientes" />
          </>}

          {clienteActual && !equipoActual && <>
            {equiposFiltrados.length ? <div className="equipo-lista-navegable">
              {paginaEquipos.items.map((equipo) => (
                <article className="equipo-navegable" key={equipo.id}>
                  <button type="button" className="equipo-navegable-abrir"
                    onClick={() => onNavigate(`/clientes/${encodeURIComponent(String(clienteAbierto))}/equipos/${equipo.id}`)}>
                    <span className="equipo-navegable-info">
                      <strong>{equipo.nombre}</strong>
                      <span>{unir(equipo.software, equipo.programa || TIPOS[equipo.tipo])}</span>
                    </span>
                    <span className="equipo-tipo-pill">{equipo.tipo === 'servidor' ? 'Servidor' : 'Equipo'}</span>
                    <span className="equipo-tarjeta-flecha">›</span>
                  </button>
                </article>
              ))}
            </div> : <div className="mapeo-vacio">{busqueda ? 'No hay equipos que coincidan con la búsqueda.' : 'Este cliente todavía no tiene equipos asociados.'}</div>}
            <Paginacion total={equiposFiltrados.length} pagina={paginaEquipos.page} paginas={paginaEquipos.pageCount}
              porPagina={paginaEquipos.pageSize} onCambiar={paginaEquipos.setPage} etiqueta="equipos" />
          </>}

          {equipoActual && <div className="equipo-historial-vista">
            <details className="equipo-gestion">
              <summary>Asignación y avisos</summary>
              <div className="equipo-accion">
                <label htmlFor={`cliente-equipo-${equipoActual.id}`}>{equipoActual.cliente_id ? 'Cliente asociado' : 'Asignar a un cliente'}</label>
                <SelectorCliente id={`cliente-equipo-${equipoActual.id}`} clientes={clientes}
                  value={clienteElegido}
                  onChange={(value) => setAsignaciones((actual) => ({ ...actual, [equipoActual.id]: value }))}
                  disabled={!clientes.length} />
                <button className="btn" disabled={guardando === equipoActual.id || !clientes.length || !hayCambioAsignacion} onClick={asignar}>
                  {guardando === equipoActual.id ? 'Guardando…' : equipoActual.cliente_id ? 'Cambiar cliente' : 'Asignar equipo'}
                </button>
                {equipoActual.cliente_id && <button className="btn sec" disabled={guardando === equipoActual.id} onClick={desasignar}>
                  {guardando === equipoActual.id ? 'Guardando…' : 'Desasignar'}
                </button>}
                <label className="equipo-vigilar">
                  <input type="checkbox" checked={equipoActual.vigilar !== 0} disabled={guardando === equipoActual.id}
                    onChange={(e) => vigilar(equipoActual, e.target.checked)} /> Avisar si no llegan copias
                </label>
                {equipoActual.vigilar !== 0 && <HorarioSemana equipo={equipoActual} onSaved={actualizarHorario} />}
              </div>
            </details>

            <section className="historial-equipo" aria-labelledby="titulo-historial-equipo">
              <div className="historial-equipo-heading">
                <h3 id="titulo-historial-equipo">Historial de copias</h3>
                <span>{historial.length}{hayMasHistorial ? '+' : ''}</span>
              </div>
              {cargandoHistorial && !historial.length ? <div className="mapeo-vacio">Cargando historial…</div>
                : historial.length ? <div className="historial-equipo-lista">
                  {historial.map((copia) => <article className="historial-equipo-fila" key={copia.id}>
                    <time>{copia.fecha}</time>
                    <Tag c={copia} />
                    {copia.desc_error && <p>{copia.codigo ? `${copia.codigo} · ` : ''}{copia.desc_error}</p>}
                  </article>)}
                </div> : !historialFallo && <div className="mapeo-vacio">Todavía no hay copias registradas para este equipo.</div>}
              {historialFallo && <div className="errbox" role="alert">{historialFallo}
                <button className="btn sec" onClick={() => cargarHistorial(equipoActual.id)}>Reintentar</button>
              </div>}
              {hayMasHistorial && <button className="btn sec" disabled={cargandoMas} onClick={() => {
                const ultima = historial[historial.length - 1];
                cargarHistorial(equipoActual.id, ultima, true);
              }}>{cargandoMas ? 'Cargando…' : 'Cargar copias anteriores'}</button>}
            </section>
          </div>}
        </>
      )}
    </section>
  </div>;
}
