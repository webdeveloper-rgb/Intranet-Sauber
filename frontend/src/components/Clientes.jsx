import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../api';
import { norm, TIPOS } from '../logic';
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

// Cliente que mejor encaja con el texto sugerido por el aviso: coincidencia exacta (nombre, código o ambos)
// o, si no, una coincidencia parcial que sea única.
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

export default function Clientes({ sesion, modo = 'clientes' }) {
  const admin = sesion?.rol === 'admin';
  const servidores = modo === 'servidores'; // 'servidores' solo muestra servidores; 'clientes' muestra el resto de equipos
  const [cat, setCat] = useState(null);
  const [equipos, setEquipos] = useState([]);
  const [asignaciones, setAsignaciones] = useState({});
  const [busquedaEquipo, setBusquedaEquipo] = useState('');
  const [filtroEquipos, setFiltroEquipos] = useState('pendientes');
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [ultimaLectura, setUltimaLectura] = useState(null);
  const [fallo, setFallo] = useState('');
  const [resumen, setResumen] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(null);
  const cargaActual = useRef(0);
  const equiposActuales = useRef([]);
  const sincronizando = useRef(false);
  const [cambiosAsignacion, setCambiosAsignacion] = useState(null);

  const cargar = useCallback(async (silencioso = false, duranteSync = false) => {
    if (sincronizando.current && !duranteSync) return { error: '', actualizado: false };
    const carga = ++cargaActual.current;
    if (!silencioso && !equiposActuales.current.length) setCargando(true);
    try {
      const [c, e] = await Promise.all([api('catalogo'), api('equipos')]);
      if (carga !== cargaActual.current) return { error: '', actualizado: false };
      if (!Array.isArray(e.equipos)) throw new Error('La respuesta de equipos no es válida.');
      if (equiposActuales.current.length && !e.equipos.length) {
        throw new Error('La actualización devolvió una lista de equipos vacía. Se mantienen los datos anteriores.');
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

  useEffect(() => {
    cargar();
    const timer = setInterval(() => cargar(true), 60000);
    return () => clearInterval(timer);
  }, [cargar]);

  const sincronizar = async () => {
    if (sincronizando.current) return;
    sincronizando.current = true;
    cargaActual.current += 1;
    const asignacionesAnteriores = new Map(equiposActuales.current.map((equipo) => [equipo.id, equipo.cliente_id ?? null]));
    setSync(true);
    setFallo('');
    setResumen(null);
    setCambiosAsignacion(null);
    let falloSync = '';
    try { setResumen(await api('sync', {})); }
    catch (e) { falloSync = e.message; }
    try {
      const resultado = await cargar(true, true);
      if (resultado.actualizado) {
        const actuales = new Map(equiposActuales.current.map((equipo) => [equipo.id, equipo.cliente_id ?? null]));
        const ids = new Set([...asignacionesAnteriores.keys(), ...actuales.keys()]);
        const idsCambiados = [...ids].filter((id) => (asignacionesAnteriores.get(id) ?? null) !== (actuales.get(id) ?? null));
        setCambiosAsignacion(idsCambiados.length);
        if (idsCambiados.length) {
          setAsignaciones((actuales) => {
            const nuevas = { ...actuales };
            idsCambiados.forEach((id) => delete nuevas[id]);
            return nuevas;
          });
        }
      }
      if (falloSync) {
        setFallo(resultado.error ? `${falloSync}. No se pudieron actualizar los datos: ${resultado.error}` : falloSync);
      }
    } finally {
      sincronizando.current = false;
      setSync(false);
    }
  };

  const asignar = async (equipo) => {
    const cliente_id = Number(asignaciones[equipo.id] || equipo.cliente_id || 0);
    if (!cliente_id) {
      setFallo(`Selecciona un cliente para ${equipo.nombre}.`);
      return;
    }
    setGuardando(equipo.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_asignar', { id: equipo.id, cliente_id });
      setMensaje(`${equipo.nombre}: asignación guardada. ${r.copias_importadas} copia${r.copias_importadas === 1 ? '' : 's'} nueva${r.copias_importadas === 1 ? '' : 's'} incorporada${r.copias_importadas === 1 ? '' : 's'}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const desasignar = async (equipo) => {
    setGuardando(equipo.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_desasignar', { id: equipo.id });
      setAsignaciones((actuales) => {
        const nuevas = { ...actuales };
        delete nuevas[equipo.id];
        return nuevas;
      });
      setMensaje(`${equipo.nombre}: equipo desasignado. ${r.copias_desasignadas} copias quedaron sin cliente.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const propios = useMemo(() => equipos.filter((e) => (e.tipo === 'servidor') === servidores), [equipos, servidores]);
  const vigilar = async (equipo, valor) => {
    setGuardando(equipo.id);
    setFallo('');
    try { await api('equipo_vigilar', { id: equipo.id, vigilar: valor }); await cargar(true); }
    catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const equiposFiltrados = useMemo(() => {
    const f = norm(busquedaEquipo.trim());
    return propios
      .filter((equipo) => filtroEquipos === 'todos' || !equipo.cliente_id)
      .filter((equipo) => !f || norm(unir(equipo.nombre, equipo.software, equipo.cliente, equipo.sugerido, equipo.origen)).includes(f))
      .sort((a, b) => Number(!!a.cliente_id) - Number(!!b.cliente_id) || a.nombre.localeCompare(b.nombre, 'es'));
  }, [propios, filtroEquipos, busquedaEquipo]);
  const paginaEquipos = usePagination(equiposFiltrados, 20, `${filtroEquipos}|${busquedaEquipo}`);

  const pendientes = propios.filter((equipo) => !equipo.cliente_id).length;
  const u = cat?.ultima;

  return <div className="clientes-page">
    <section className="mapeo-panel" aria-labelledby="titulo-mapeo">
      <div className="mapeo-heading">
        <div>
          <span className="eyebrow">Trabajo técnico</span>
          <h2 id="titulo-mapeo">{servidores ? 'Asignar servidores a clientes' : 'Asignar equipos a clientes'}</h2>
          <p>{servidores ? 'Vincula cada servidor detectado con su cliente. Sus copias quedan asociadas automáticamente.' : 'Vincula cada equipo detectado con su cliente. Las copias recibidas quedan asociadas automáticamente.'}</p>
        </div>
        <div className="mapeo-kpi" aria-label={`${pendientes} ${servidores ? 'servidores' : 'equipos'} pendientes`}>
          <strong>{pendientes}</strong>
          <span>por asignar</span>
        </div>
      </div>

      <div className="mapeo-toolbar">
        <label className="mapeo-busqueda">
          <span className="sr-only">Buscar equipo o cliente</span>
          <input type="search" placeholder={servidores ? 'Buscar servidor, software o cliente…' : 'Buscar equipo, software o cliente…'} value={busquedaEquipo} onChange={(e) => setBusquedaEquipo(e.target.value)} />
        </label>
        <div className="mapeo-filtros" role="group" aria-label="Filtrar equipos">
          <button className={filtroEquipos === 'pendientes' ? 'activo' : ''} aria-pressed={filtroEquipos === 'pendientes'} onClick={() => setFiltroEquipos('pendientes')}>Pendientes <span>{pendientes}</span></button>
          <button className={filtroEquipos === 'todos' ? 'activo' : ''} aria-pressed={filtroEquipos === 'todos'} onClick={() => setFiltroEquipos('todos')}>Todos <span>{propios.length}</span></button>
        </div>
        {admin && <button className="btn sec" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Sincronizar clientes'}</button>}
      </div>
      {sync && <p className="sync-estado" role="status" aria-live="polite">
        Sincronizando clientes. Los equipos actuales permanecen visibles.
      </p>}

      {ultimaLectura && <p className={`mail-estado${ultimaLectura.alerta || ultimaLectura.ok === 0 ? ' mail-estado-error' : ''}`}>
        {ultimaLectura.fecha
          ? <>Última recepción de avisos: {ultimaLectura.fecha} · {ultimaLectura.ok === 0 ? 'con errores' : ultimaLectura.ok === 1 ? 'correcta' : 'sin datos'} · {ultimaLectura.nuevos} nuevos · {ultimaLectura.ignorados} no reconocidos</>
          : 'Todavía no se ha recibido ningún aviso de n8n.'}
        {ultimaLectura.alerta && ` · Sin recepción durante ${ultimaLectura.horas_desde ?? 'un periodo desconocido'} h (umbral: ${ultimaLectura.umbral_horas} h)`}
        {ultimaLectura.mensaje ? ` · ${ultimaLectura.mensaje}` : ''}
      </p>}
      {u && <p className={`mail-estado${u.ok === 0 ? ' mail-estado-error' : ''}`}>
        Última sincronización de clientes: {u.fecha} · {u.ok === 1 ? 'correcta' : u.ok === 0 ? 'con errores' : 'sin sincronizar'}
        {u.mensaje ? ` · ${u.mensaje}` : ''}
      </p>}

      {fallo && <div className="errbox" role="alert">{fallo} <button className="btn sec" onClick={() => cargar()}>Reintentar</button></div>}
      {mensaje && <div className="okmsg" role="status">{mensaje}</div>}
      {admin && cat && !cat.configurado && <div className="errbox">La API de clientes no está configurada en <code>config/config.php</code>.</div>}
      {u && u.ok === 0 && <div className="errbox">Falló la última sincronización de clientes: {u.mensaje}</div>}
      {resumen && <div className="okmsg">
        Sincronizados {resumen.clientes} clientes{resumen.omitidos ? ` (${resumen.omitidos} sin identificador, omitidos)` : ''}.
        {resumen.total_api_clientes != null && ` En la API: ${resumen.total_api_clientes} clientes y ${resumen.filas_con_servicio} filas con el servicio ${resumen.servicio}.`}
        {resumen.filas_sin_cliente > 0 && ` ${resumen.filas_sin_cliente} filas con el servicio no encuentran su cliente (${(resumen.ejemplos_sin_cliente || []).slice(0, 5).join(' | ')}).`}
      </div>}
      {cambiosAsignacion !== null && <p className="sync-cambios" role="status">
        {cambiosAsignacion
          ? `La asignación de ${cambiosAsignacion} equipo${cambiosAsignacion === 1 ? '' : 's'} cambió durante la sincronización.`
          : 'No cambiaron las asignaciones de equipos.'}
      </p>}

      {cargando && !equipos.length ? <div className="mapeo-vacio">Cargando equipos…</div> : equiposFiltrados.length ? (
        <div className="mapeo-lista">
          {paginaEquipos.items.map((equipo) => {
            const asignado = !!equipo.cliente_id;
            const sugerencia = equipo.sugerido ? sugerirCliente(cat?.clientes || [], equipo.sugerido) : undefined;
            const clienteSeleccionado = asignaciones[equipo.id] ?? equipo.cliente_id ?? sugerencia?.id ?? '';
            const clienteElegido = cat?.clientes?.find((c) => Number(c.id) === Number(clienteSeleccionado));
            const hayCambios = Number(clienteSeleccionado) > 0 && Number(clienteSeleccionado) !== Number(equipo.cliente_id || 0);
            const actualizarHorario = (id, dias) => {
              const actualizar = (lista) => lista.map((item) => item.id === id ? { ...item, dias_copia: dias } : item);
              cargaActual.current += 1;
              equiposActuales.current = actualizar(equiposActuales.current);
              setEquipos(actualizar);
            };
            return <article className={`equipo-card${asignado ? ' equipo-card-asignado' : ' equipo-card-pendiente'}`} key={equipo.id}>
              <div className="equipo-info">
                <div className="equipo-titulo">
                  <h3 title={equipo.nombre}>{equipo.nombre}</h3>
                  <span className="equipo-software">{equipo.software}</span>
                  {equipo.tipo && <span className="equipo-software">{equipo.programa || TIPOS[equipo.tipo]}</span>}
                </div>
                <div className="equipo-detalles">
                  <span className={`equipo-badge${asignado ? ' asignado' : ''}`}>
                    {asignado ? 'Asignado' : 'Pendiente de asignación'}
                  </span>
                  <span className="equipo-copias">{equipo.pendientes} aviso{equipo.pendientes === 1 ? '' : 's'} {asignado ? 'por importar' : 'recibido'}</span>
                </div>
                {asignado && <p className="equipo-cliente-actual">Cliente actual <strong>{equipo.cliente}</strong></p>}
                {!asignado && sugerencia && <p className="equipo-sugerencia">Coincidencia sugerida <strong>{sugerencia.nombre}</strong>{sugerencia.codigo ? ` · ${sugerencia.codigo}` : ''}</p>}
                {!asignado && equipo.sugerido && !sugerencia && <p className="equipo-sugerencia">Referencia del aviso <strong>{equipo.sugerido}</strong></p>}
              </div>
              <div className="equipo-accion">
                <label htmlFor={`cliente-equipo-${equipo.id}`}>{asignado ? 'Cambiar cliente asociado' : 'Selecciona el cliente de este equipo'}</label>
                <SelectorCliente id={`cliente-equipo-${equipo.id}`} clientes={cat?.clientes || []}
                  value={clienteSeleccionado}
                  onChange={(value) => setAsignaciones((a) => ({ ...a, [equipo.id]: value }))}
                  disabled={!cat?.clientes?.length} />
                <button className="btn" disabled={guardando === equipo.id || !cat?.clientes?.length || !hayCambios} onClick={() => asignar(equipo)}>
                  {guardando === equipo.id ? 'Guardando…' : asignado ? 'Guardar cambio' : 'Asignar equipo'}
                </button>
                {asignado && <button className="btn sec" disabled={guardando === equipo.id} onClick={() => desasignar(equipo)}>
                  {guardando === equipo.id ? 'Guardando…' : 'Desasignar equipo'}
                </button>}
                <label className="equipo-vigilar">
                  <input type="checkbox" checked={equipo.vigilar !== 0} disabled={guardando === equipo.id}
                    onChange={(e) => vigilar(equipo, e.target.checked)} /> Avisar si no llegan copias
                </label>
                {equipo.vigilar !== 0 && <HorarioSemana equipo={equipo} onSaved={actualizarHorario} />}
                <p className={`equipo-seleccion${hayCambios ? ' hay-cambios' : ''}`} aria-live="polite">
                  {hayCambios ? `Se asociará a ${clienteElegido?.nombre || 'el cliente seleccionado'}` : asignado ? 'Sin cambios pendientes' : 'Elige un cliente para continuar'}
                </p>
              </div>
            </article>;
          })}
        </div>
      ) : <div className="mapeo-vacio">
        {busquedaEquipo ? 'No hay equipos que coincidan con la búsqueda.' : filtroEquipos === 'pendientes' ? (servidores ? 'No hay servidores pendientes. Los nuevos avisos aparecerán aquí.' : 'No hay equipos pendientes. Los nuevos avisos aparecerán aquí.') : (servidores ? 'Todavía no se han detectado servidores en los avisos.' : 'Todavía no se han detectado equipos en los avisos.')}
      </div>}
      <Paginacion total={equiposFiltrados.length} pagina={paginaEquipos.page} paginas={paginaEquipos.pageCount}
        porPagina={paginaEquipos.pageSize} onCambiar={paginaEquipos.setPage} etiqueta="equipos" />
    </section>
  </div>;
}