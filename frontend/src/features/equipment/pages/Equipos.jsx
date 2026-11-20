import { useEffect, useMemo, useState } from 'react';
import { api } from '../../../shared/api/client';
import { norm } from '../../../shared/lib/logic';
import { rutaHref } from '../../../app/routes';
import { usePagination } from '../../../shared/hooks/usePagination';
import BarraFiltros from '../../../shared/ui/BarraFiltros';
import PageHeading from '../../../shared/ui/PageHeading';
import Paginacion from '../../../shared/ui/Paginacion';

const ESTADOS = [['', 'Todos los estados'], ['incidencias', 'Con incidencias'], ['copias', 'Con fallos de copia'],
  ['sin-copia', 'Sin copia esperada'], ['sin-informe', 'Sin informe >24 h'], ['sin-asignar', 'Sin asignar'], ['en-curso', 'Copias en curso']];
const TIPOS = [['', 'Todos los tipos'], ['servidor', 'Servidores'], ['usuario', 'Equipos de usuario'], ['programa', 'Programas']];
const AGENTES = [['', 'Cualquier cobertura'], ['con', 'Con agente'], ['sin', 'Sin agente']];
const COPIAS = [['', 'Cualquier cobertura'], ['con', 'Con copias recibidas'], ['sin', 'Sin copias recibidas']];
const ORDENES = [['', 'Prioridad de atención'], ['nombre', 'Nombre de equipo'], ['cliente', 'Cliente'], ['informe', 'Último informe del agente']];
const requiereResumen = (estado) => ['incidencias', 'sin-copia', 'sin-informe', 'en-curso'].includes(estado);
const incidencia = (e) => Number(e.errores_pendientes) > 0 || Number(e.total_errores_evento) > 0 || e.sin_copia || e.sin_informe;
const prioridad = (e) => e.critico ? 4 : e.tipo === 'servidor' && (Number(e.errores_pendientes) > 0 || e.sin_copia) ? 3 : incidencia(e) ? 2 : Number(e.copias_en_curso) > 0 ? 1 : 0;
const validar = (valor, opciones) => opciones.some(([key]) => key === valor) ? valor : '';

export default function Equipos({ filtros = {}, refreshSignal = 0, onNavigate }) {
  const [datos, setDatos] = useState(null);
  const [estados, setEstados] = useState(null);
  const [error, setError] = useState('');
  const [errorEstados, setErrorEstados] = useState('');
  const [cargando, setCargando] = useState(true);
  const [revision, setRevision] = useState(0);
  const q = filtros.q || '';
  const cliente = filtros.cliente || '';
  const tipo = validar(filtros.tipo, TIPOS);
  const estado = validar(filtros.estado, ESTADOS);
  const agente = validar(filtros.agente, AGENTES);
  const copias = validar(filtros.copias, COPIAS);
  const orden = validar(filtros.orden, ORDENES);
  const actuales = { q, cliente, tipo, estado, agente, copias, orden };

  useEffect(() => {
    let activo = true;
    let pendiente = false;
    const cargar = async () => {
      if (pendiente) return;
      pendiente = true;
      const [listado, resumen] = await Promise.allSettled([api('equipos'), api('dashboard_resumen')]);
      if (!activo) return;
      if (listado.status === 'fulfilled' && Array.isArray(listado.value.equipos)) {
        setDatos(listado.value.equipos); setError('');
      } else setError(listado.status === 'rejected' ? listado.reason.message : 'La respuesta de equipos no es válida.');
      if (resumen.status === 'fulfilled' && Array.isArray(resumen.value.equipos)) {
        setEstados(resumen.value.equipos); setErrorEstados('');
      } else {
        setEstados(null);
        setErrorEstados(resumen.status === 'rejected' ? resumen.reason.message : 'La respuesta de estados no es válida.');
      }
      setCargando(false); pendiente = false;
    };
    setCargando(true); cargar();
    const timer = setInterval(() => { if (!document.hidden) cargar(); }, 60_000);
    return () => { activo = false; clearInterval(timer); };
  }, [revision, refreshSignal]);

  const equipos = useMemo(() => {
    const porId = new Map((estados || []).map((e) => [Number(e.id), e]));
    return (datos || []).map((e) => ({ ...e, total_errores_evento: 0, copias_en_curso: 0, ...porId.get(Number(e.id)), estadoDisponible: estados !== null }));
  }, [datos, estados]);
  const clientes = useMemo(() => [...new Map((datos || []).filter((e) => e.cliente_id)
    .map((e) => [String(e.cliente_id), e.cliente || `Cliente ${e.cliente_id}`])).entries()]
    .sort((a, b) => a[1].localeCompare(b[1], 'es')), [datos]);
  const filtroNoDisponible = estados === null && requiereResumen(estado);
  const filas = useMemo(() => equipos.filter((e) => {
    if (filtroNoDisponible) return false;
    if (q && !norm([e.nombre, e.monitor_alias, e.cliente, e.software, e.programa].filter(Boolean).join(' ')).includes(norm(q.trim()))) return false;
    if (cliente && (cliente === 'sin-asignar' ? Boolean(e.cliente_id) : String(e.cliente_id) !== cliente)) return false;
    if (tipo && e.tipo !== tipo) return false;
    if (agente && Boolean(e.tiene_monitor) !== (agente === 'con')) return false;
    if (copias && Boolean(e.tiene_copias) !== (copias === 'con')) return false;
    if (estado === 'incidencias' && !incidencia(e)) return false;
    if (estado === 'copias' && !Number(e.errores_pendientes)) return false;
    if (estado === 'sin-copia' && !e.sin_copia) return false;
    if (estado === 'sin-informe' && !e.sin_informe) return false;
    if (estado === 'sin-asignar' && e.cliente_id) return false;
    if (estado === 'en-curso' && !Number(e.copias_en_curso)) return false;
    return true;
  }).sort((a, b) => {
    if (orden === 'cliente') return (a.cliente || '').localeCompare(b.cliente || '', 'es') || a.nombre.localeCompare(b.nombre, 'es');
    if (orden === 'informe') return (b.monitor_last_seen || '').localeCompare(a.monitor_last_seen || '') || a.nombre.localeCompare(b.nombre, 'es');
    if (!orden && prioridad(a) !== prioridad(b)) return prioridad(b) - prioridad(a);
    return a.nombre.localeCompare(b.nombre, 'es', { numeric: true });
  }), [equipos, q, cliente, tipo, agente, copias, estado, orden, filtroNoDisponible]);
  const pagina = usePagination(filas, 20, JSON.stringify(actuales));
  const actualizar = (cambios) => {
    const params = new URLSearchParams(Object.entries({ ...actuales, ...cambios }).filter(([, value]) => value));
    onNavigate(`/equipos${params.size ? `?${params}` : ''}`, { replace: true });
  };
  const limpiar = () => onNavigate('/equipos', { replace: true });
  const campos = [
    ['cliente', 'Cliente', [['', 'Todos los clientes'], ['sin-asignar', 'Sin asignar'], ...clientes], cliente],
    ['tipo', 'Tipo', TIPOS, tipo], ['estado', 'Estado', ESTADOS, estado],
    ['agente', 'Agente Monitor', AGENTES, agente], ['copias', 'Copias', COPIAS, copias], ['orden', 'Ordenar por', ORDENES, orden],
  ];
  const activos = [
    ...(q ? [{ clave: 'q', etiqueta: `Buscar: ${q}` }] : []),
    ...campos.filter(([key, , , value]) => value && key !== 'orden').map(([key, label, opciones, value]) => ({
      clave: key, etiqueta: `${label}: ${opciones.find(([id]) => id === value)?.[1] || value}`,
    })),
  ];
  const abrir = (event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); onNavigate(path);
  };
  const abrirFila = (event, path) => {
    if (event.target.closest('a,button,input,select,textarea')) return;
    abrir(event, path);
  };
  return <section className="equipos-page" aria-labelledby="equipos-titulo" aria-busy={cargando}>
    <PageHeading id="equipos-titulo" eyebrow="Sauber Ofimática" title="Equipos"
      description="Todos los equipos registrados, sus clientes y su estado de monitoreo."
      actions={<button className="btn sec" disabled={cargando} onClick={() => setRevision((n) => n + 1)}>{cargando ? 'Actualizando…' : 'Actualizar'}</button>} />
    {error && <div className="errbox" role="alert">No se pudo actualizar el listado: {error}. {datos && 'Se mantienen los últimos datos recibidos.'} <button className="btn sec" onClick={() => setRevision((n) => n + 1)}>Reintentar</button></div>}
    {errorEstados && <div className="estado-alertas" role="status">No se pudo actualizar el estado de monitoreo: {errorEstados}. Las incidencias de hardware, los eventos y las copias ausentes no están disponibles. <button className="btn sec" onClick={() => setRevision((n) => n + 1)}>Reintentar</button></div>}
    <BarraFiltros title="Filtrar equipos" className="equipos-filtros"
      search={{ id: 'equipos-global-buscar', value: q, placeholder: 'Nombre, alias, cliente o software', onChange: (e) => actualizar({ q: e.target.value }) }}
      fields={campos.map(([key, label, opciones, value]) => ({ id: `equipos-global-${key}`, label, type: 'select', value,
        ariaLabel: label, options: opciones, onChange: (e) => actualizar({ [key]: e.target.value }) }))}
      count={filtroNoDisponible ? 'Estado de monitoreo no disponible' : datos ? `${filas.length} de ${datos.length} equipos` : 'Esperando equipos'}
      activeFilters={activos} onRemoveFilter={(key) => actualizar({ [key]: '' })} onClear={limpiar} />
    <div className="dashboard-panel equipos-listado">
      <div className="equipos-table-wrap"><table className="equipos-table"><thead><tr>
        <th>Equipo / tipo</th><th>Cliente</th><th>Estado de copias</th><th>Último informe del agente</th><th>Atención</th><th><span className="sr-only">Acciones</span></th>
      </tr></thead><tbody>{pagina.items.map((e) => {
        const path = `/clientes/${e.cliente_id || 'sin-asignar'}/equipos/${e.id}`;
        return <tr key={e.id} className="equipos-row-link" tabIndex={0} role="link"
          aria-label={`Abrir ficha de ${e.nombre}`} onClick={(event) => abrirFila(event, path)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              onNavigate(path);
            }
          }}><td><a href={rutaHref(path)} onClick={(event) => abrir(event, path)}>{e.monitor_alias || e.nombre}</a>
          <small>{e.monitor_alias ? `${e.nombre} · ` : ''}{e.tipo === 'servidor' ? 'Servidor' : e.tipo === 'usuario' ? 'Equipo de usuario' : e.tipo === 'programa' ? 'Programa' : 'Sin tipo'}</small></td>
          <td>{e.cliente_id ? <a href={rutaHref(`/clientes/${e.cliente_id}`)} onClick={(event) => abrir(event, `/clientes/${e.cliente_id}`)}>{e.cliente || 'Ver cliente'}</a> : <span className="equipos-muted">Sin asignar</span>}</td>
          <td><span className={Number(e.errores_pendientes) || e.sin_copia ? 'equipos-warning' : ''}>{e.sin_copia ? 'Sin copia esperada' : Number(e.errores_pendientes) ? `${e.errores_pendientes} ${Number(e.errores_pendientes) === 1 ? 'fallo' : 'fallos'} de copia` : !e.tiene_copias ? 'Sin copias recibidas' : Number(e.copias_en_curso) ? 'Copia en curso' : 'Sin fallos de copia registrados'}</span>
            <small>{e.ultima_copia ? `Última: ${e.ultima_copia}` : e.tiene_copias ? 'Fecha no disponible' : '—'}{e.software ? ` · ${e.software}` : ''}</small></td>
          <td><span className={e.sin_informe ? 'equipos-warning' : ''}>{!e.tiene_monitor ? 'Sin agente' : e.sin_informe ? 'Sin informar >24 h' : e.monitor_last_seen || 'Sin fecha de informe'}</span>
            {e.sin_informe && <small>{e.monitor_last_seen}</small>}</td>
          <td><div className="queue-badges">{e.critico && <span className="queue-priority level-3">Hardware crítico</span>}
            {Number(e.total_errores_evento) > 0 && <span className="queue-badge error">Eventos / alertas: {e.total_errores_evento}</span>}
            {e.sin_copia && <span className="queue-badge warning">Revisar copias</span>}{e.sin_informe && <span className="queue-badge warning">Revisar agente</span>}
            {!incidencia(e) && <span className="equipos-muted">{e.estadoDisponible ? 'Sin incidencias detectadas' : 'Estado parcial'}</span>}
            {Number(e.errores_pendientes) > 0 && !e.critico && <span className="queue-badge error">Revisar fallos de copia</span>}
          </div></td><td><a className="equipos-open" href={rutaHref(path)} onClick={(event) => abrir(event, path)} aria-label={`Abrir ficha de ${e.nombre}`}>Ver ficha ↗</a></td></tr>;
      })}</tbody></table></div>
      {!filas.length && <div className="empty-state" role="status">{filtroNoDisponible ? 'No se puede aplicar este filtro hasta recuperar los estados de monitoreo.' : !datos ? cargando ? 'Cargando equipos…' : 'No se pudieron cargar los equipos.' : datos.length ? 'No hay equipos que coincidan con los filtros.' : 'Todavía no hay equipos registrados.'}</div>}
      <Paginacion total={filas.length} pagina={pagina.page} paginas={pagina.pageCount} porPagina={pagina.pageSize} onCambiar={pagina.setPage} etiqueta="equipos" />
      <p className="dashboard-queue-note">Las fechas corresponden a los últimos datos recibidos. La falta de informes no confirma una desconexión. «Sin copias recibidas» indica que no hay historial, no un fallo confirmado.</p>
    </div>
  </section>;
}
