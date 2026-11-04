import { useState, useEffect, useCallback, useMemo } from 'react';
import { api } from '../api';
import { norm } from '../logic';
import Paginacion from './Paginacion';
import SelectorCliente from './SelectorCliente';
import { usePagination } from '../hooks/usePagination';

const unir = (...v) => v.filter(Boolean).join(' · ');
const CLIENTES_POR_PAGINA = 15;

export default function Clientes({ sesion }) {
  const admin = sesion?.rol === 'admin';
  const [cat, setCat] = useState(null);
  const [equipos, setEquipos] = useState([]);
  const [asignaciones, setAsignaciones] = useState({});
  const [busquedaCliente, setBusquedaCliente] = useState('');
  const [busquedaEquipo, setBusquedaEquipo] = useState('');
  const [filtroEquipos, setFiltroEquipos] = useState('pendientes');
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [correoSync, setCorreoSync] = useState(false);
  const [ultimaLectura, setUltimaLectura] = useState(null);
  const [fallo, setFallo] = useState('');
  const [resumen, setResumen] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(null);

  const cargar = useCallback(async (silencioso = false) => {
    if (!silencioso) setCargando(true);
    try {
      const [c, e] = await Promise.all([api('catalogo'), api('equipos')]);
      setCat(c);
      setEquipos(e.equipos);
      setUltimaLectura(e.correo);
      setFallo('');
    } catch (e) {
      setFallo(e.message);
    } finally {
      if (!silencioso) setCargando(false);
    }
  }, []);

  useEffect(() => {
    cargar();
    const timer = setInterval(() => cargar(true), 60000);
    return () => clearInterval(timer);
  }, [cargar]);

  const sincronizar = async () => {
    setSync(true);
    setFallo('');
    try { setResumen(await api('sync', {})); await cargar(true); }
    catch (e) { setFallo(e.message); }
    finally { setSync(false); }
  };

  const leerCorreo = async () => {
    setCorreoSync(true);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('correo_sync', {});
      setMensaje(`Leídos ${r.leidas} avisos nuevos${r.omitidas ? `; ${r.omitidas} no reconocidos` : ''}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setCorreoSync(false); }
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

  const clientesFiltrados = useMemo(() => {
    const f = norm(busquedaCliente.trim());
    if (!cat) return [];
    return cat.clientes.filter((c) => !f || norm(unir(c.codigo, c.nombre, c.cif, c.email, c.localidad)).includes(f));
  }, [cat, busquedaCliente]);

  const equiposFiltrados = useMemo(() => {
    const f = norm(busquedaEquipo.trim());
    return equipos
      .filter((equipo) => filtroEquipos === 'todos' || !equipo.cliente_id)
      .filter((equipo) => !f || norm(unir(equipo.nombre, equipo.software, equipo.cliente, equipo.sugerido, equipo.origen)).includes(f))
      .sort((a, b) => Number(!!a.cliente_id) - Number(!!b.cliente_id) || a.nombre.localeCompare(b.nombre, 'es'));
  }, [equipos, filtroEquipos, busquedaEquipo]);
  const paginaEquipos = usePagination(equiposFiltrados, 20, `${filtroEquipos}|${busquedaEquipo}`);
  const paginaClientes = usePagination(clientesFiltrados, CLIENTES_POR_PAGINA, busquedaCliente);

  const pendientes = equipos.filter((equipo) => !equipo.cliente_id).length;
  const u = cat?.ultima;

  return <div className="clientes-page">
    <section className="mapeo-panel" aria-labelledby="titulo-mapeo">
      <div className="mapeo-heading">
        <div>
          <span className="eyebrow">Trabajo técnico</span>
          <h2 id="titulo-mapeo">Asignar equipos a clientes</h2>
          <p>Vincula cada equipo detectado con su cliente. Las copias recibidas quedan asociadas automáticamente.</p>
        </div>
        <div className="mapeo-kpi" aria-label={`${pendientes} equipos pendientes`}>
          <strong>{pendientes}</strong>
          <span>por asignar</span>
        </div>
      </div>

      <div className="mapeo-toolbar">
        <label className="mapeo-busqueda">
          <span className="sr-only">Buscar equipo o cliente</span>
          <input type="search" placeholder="Buscar equipo, software o cliente…" value={busquedaEquipo} onChange={(e) => setBusquedaEquipo(e.target.value)} />
        </label>
        <div className="mapeo-filtros" role="group" aria-label="Filtrar equipos">
          <button className={filtroEquipos === 'pendientes' ? 'activo' : ''} aria-pressed={filtroEquipos === 'pendientes'} onClick={() => setFiltroEquipos('pendientes')}>Pendientes <span>{pendientes}</span></button>
          <button className={filtroEquipos === 'todos' ? 'activo' : ''} aria-pressed={filtroEquipos === 'todos'} onClick={() => setFiltroEquipos('todos')}>Todos <span>{equipos.length}</span></button>
        </div>
        {admin && <button className="btn" disabled={correoSync} onClick={leerCorreo}>{correoSync ? 'Leyendo buzón…' : 'Leer avisos'}</button>}
      </div>

      {ultimaLectura && <p className={`mail-estado${ultimaLectura.ok === 0 ? ' mail-estado-error' : ''}`}>
        Última lectura: {ultimaLectura.fecha} · {ultimaLectura.ok === 0 ? 'con errores' : 'correcta'} · {ultimaLectura.nuevos} nuevos · {ultimaLectura.ignorados} no reconocidos
        {ultimaLectura.mensaje ? ` · ${ultimaLectura.mensaje}` : ''}
      </p>}

      {fallo && <div className="errbox" role="alert">{fallo} <button className="btn sec" onClick={() => cargar()}>Reintentar</button></div>}
      {mensaje && <div className="okmsg" role="status">{mensaje}</div>}
      {admin && cat && !cat.configurado && <div className="errbox">La API de clientes no está configurada en <code>config/config.php</code>.</div>}

      {cargando && !equipos.length ? <div className="mapeo-vacio">Cargando equipos…</div> : equiposFiltrados.length ? (
        <div className="mapeo-lista">
          {paginaEquipos.items.map((equipo) => {
            const asignado = !!equipo.cliente_id;
            const sugerencia = equipo.sugerido && cat?.clientes?.find((c) =>
              norm(unir(c.nombre, c.codigo)) === norm(equipo.sugerido));
            const clienteSeleccionado = asignaciones[equipo.id] ?? equipo.cliente_id ?? sugerencia?.id ?? '';
            return <article className={`equipo-card${asignado ? ' equipo-card-asignado' : ' equipo-card-pendiente'}`} key={equipo.id}>
              <div className="equipo-info">
                <div className="equipo-titulo">
                  <h3 title={equipo.nombre}>{equipo.nombre}</h3>
                  <span className="equipo-software">{equipo.software}</span>
                </div>
                <p className="equipo-estado">
                  <span className={`estado-punto${asignado ? ' asignado' : ''}`} />
                  {asignado ? equipo.cliente : 'Pendiente de asignación'}
                </p>
                {equipo.sugerido && <p className="equipo-sugerencia">Cliente sugerido: <strong>{equipo.sugerido}</strong></p>}
                <span className="equipo-copias">{equipo.pendientes} aviso{equipo.pendientes === 1 ? '' : 's'} {asignado ? 'por importar' : 'recibido'}</span>
              </div>
              <div className="equipo-accion">
                <label htmlFor={`cliente-equipo-${equipo.id}`}>Cliente</label>
                <SelectorCliente id={`cliente-equipo-${equipo.id}`} clientes={cat?.clientes || []}
                  value={clienteSeleccionado}
                  onChange={(value) => setAsignaciones((a) => ({ ...a, [equipo.id]: value }))}
                  disabled={!cat?.clientes?.length} />
                <button className="btn" disabled={guardando === equipo.id || !cat?.clientes?.length} onClick={() => asignar(equipo)}>
                  {guardando === equipo.id ? 'Guardando…' : asignado ? 'Guardar cambio' : 'Asignar equipo'}
                </button>
              </div>
            </article>;
          })}
        </div>
      ) : <div className="mapeo-vacio">
        {busquedaEquipo ? 'No hay equipos que coincidan con la búsqueda.' : filtroEquipos === 'pendientes' ? 'No hay equipos pendientes. Los nuevos avisos aparecerán aquí.' : 'Todavía no se han detectado equipos en los correos.'}
      </div>}
      <Paginacion total={equiposFiltrados.length} pagina={paginaEquipos.page} paginas={paginaEquipos.pageCount}
        porPagina={paginaEquipos.pageSize} onCambiar={paginaEquipos.setPage} etiqueta="equipos" />
    </section>

    <section className="directorio-panel" aria-labelledby="titulo-directorio">
      <div className="directorio-heading">
        <div>
          <span className="eyebrow">Referencia</span>
          <h2 id="titulo-directorio">Directorio de clientes</h2>
          <p>{u ? `Última sincronización: ${u.fecha}${u.ok === 1 ? ' · Correcta' : u.ok === 0 ? ' · Con error' : ''}.` : 'Clientes disponibles para asignar equipos.'}</p>
        </div>
        {admin && <button className="btn sec" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Sincronizar clientes'}</button>}
      </div>

      {u && u.ok === 0 && <div className="errbox">Falló la última sincronización: {u.mensaje}</div>}
      {resumen && <div className="okmsg">Sincronizados {resumen.clientes} clientes{resumen.omitidos ? ` (${resumen.omitidos} sin identificador, omitidos)` : ''}.</div>}
      <div className="directorio-toolbar">
        <label className="mapeo-busqueda">
          <span className="sr-only">Buscar en el directorio de clientes</span>
          <input type="search" placeholder="Buscar por nombre, código, CIF o correo…" value={busquedaCliente} onChange={(e) => setBusquedaCliente(e.target.value)} />
        </label>
      <span className="directorio-total">{clientesFiltrados.length} cliente{clientesFiltrados.length === 1 ? '' : 's'}</span>
      </div>

      {paginaClientes.items.map((c) => <article key={c.id} className="cliente-row">
        <strong>{c.nombre || '(sin nombre)'}</strong>
        {c.codigo && <span className="cliente-codigo">{c.codigo}</span>}
        <p>{unir(c.cif, c.telefono, c.email, [c.localidad, c.provincia].filter(Boolean).join(', ')) || 'Sin más datos'}</p>
      </article>)}
      {!clientesFiltrados.length && <div className="mapeo-vacio">{cargando ? 'Cargando clientes…' : cat?.clientes.length ? 'No hay clientes que coincidan.' : 'Todavía no hay clientes sincronizados.'}</div>}

      <Paginacion total={clientesFiltrados.length} pagina={paginaClientes.page} paginas={paginaClientes.pageCount}
        porPagina={paginaClientes.pageSize} onCambiar={paginaClientes.setPage} etiqueta="clientes" />
      {cat?.auto_minutos > 0 && <p className="note">El directorio se sincroniza automáticamente cada {cat.auto_minutos} minutos mientras se usa el panel.</p>}
    </section>
  </div>;
}
