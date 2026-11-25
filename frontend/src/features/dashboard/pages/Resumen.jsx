import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../../../shared/api/client';
import { norm } from '../../../shared/lib/logic';
import { rutaHref } from '../../../app/routes';
import PageHeading from '../../../shared/ui/PageHeading';
import Paginacion from '../../../shared/ui/Paginacion';
import { usePagination } from '../../../shared/hooks/usePagination';
import Icon from '../../../shared/ui/Icon';

const ESTADOS = [['OK', 'Correctas', 'ok'], ['ERROR', 'Fallidas', 'error'], ['WARNING', 'Advertencias', 'warning'], ['RUNNING', 'En curso', 'running'], ['REVISION', 'Errores pendientes de clasificación', 'pending']];
const conError = (e) => Number(e.errores_pendientes) > 0 || Number(e.total_errores_evento) > 0 || e.sin_copia || e.sin_informe;
const prioridad = (e) => e.critico ? 3 : ((e.tipo === 'servidor' && (Number(e.errores_pendientes) > 0 || e.sin_copia)) ? 2 : conError(e) ? 1 : 0);
const destino = (e) => `/clientes/${e.cliente_id || 'sin-asignar'}/equipos/${e.id}`;
const FILTROS = [['todos', 'Toda la actividad'], ['incidencias', 'Con incidencias'], ['copias', 'Fallos de copia'], ['sin-copia', 'Error crítico sin copia'], ['sin-informe', 'Sin informe >24 h'], ['en-curso', 'Copias en curso']];

export default function Resumen({ refreshSignal, onNavigate }) {
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);
  const [revision, setRevision] = useState(0);
  const cargaInicialCompletada = useRef(false);
  const [busqueda, setBusqueda] = useState('');
  const [filtro, setFiltro] = useState('todos');
  useEffect(() => {
    let activo = true;
    let pendiente = false;
    const cargar = async () => {
      if (pendiente) return;
      pendiente = true;
      try {
        const data = await api('dashboard_resumen');
        if (activo) { setDatos(data); setError(''); }
      } catch (e) { if (activo) setError(e.message); }
      finally {
        pendiente = false;
        if (activo) {
          cargaInicialCompletada.current = true;
          setCargando(false);
        }
      }
    };
    if (!cargaInicialCompletada.current) setCargando(true);
    cargar();
    const timer = setInterval(() => { if (!document.hidden) cargar(); }, 60_000);
    return () => { activo = false; clearInterval(timer); };
  }, [refreshSignal, revision]);
  const navegar = useCallback((event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); onNavigate(path);
  }, [onNavigate]);
  const equipos = datos?.equipos || [];
  const resumen = datos?.resumen;
  const filas = useMemo(() => (datos?.equipos || []).filter((e) => {
    if (filtro === 'incidencias' && !conError(e)) return false;
    if (filtro === 'copias' && !Number(e.errores_pendientes)) return false;
    if (filtro === 'sin-copia' && !e.sin_copia) return false;
    if (filtro === 'sin-informe' && !e.sin_informe) return false;
    if (filtro === 'en-curso' && !Number(e.copias_en_curso)) return false;
    return norm([e.nombre, e.monitor_alias, e.cliente, e.software].filter(Boolean).join(' ')).includes(norm(busqueda.trim()));
  }).sort((a, b) => prioridad(b) - prioridad(a)
    || (Number(b.errores_pendientes) + Number(b.total_errores_evento)) - (Number(a.errores_pendientes) + Number(a.total_errores_evento))
    || a.nombre.localeCompare(b.nombre, 'es')), [datos, busqueda, filtro]);
  const pagina = usePagination(filas, 6, `${filtro}|${busqueda}`);
  const valor = (n) => datos ? Number(n || 0).toLocaleString('es-ES') : '—';
  const metricas = [
    ['Equipos con incidencias', equipos.filter(conError).length, 'Estado actual de los equipos', 'incidencias', 'error'],
    ['Equipos con fallos de copia', equipos.filter((e) => Number(e.errores_pendientes) > 0).length, 'Errores conservados pendientes', 'copias', 'error'],
    ['Error crítico sin copia', resumen?.sin_copia, 'Sin copia recibida en más de 24 h', 'sin-copia', 'warning'],
    ['Agentes sin informar', resumen?.sin_informe, 'Último informe hace más de 24 h', 'sin-informe', 'warning'],
    ['Equipos sin asignar', resumen?.sin_asignar, 'Pendientes de vincular a un cliente', 'asignar', 'neutral'],
  ];
  const copias = Object.fromEntries((datos?.copias || []).map((c) => [c.estado, Number(c.total)]));
  const totalCopias = Object.values(copias).reduce((a, b) => a + b, 0);
  const ingesta = datos?.integraciones.ingesta;
  const sync = datos?.integraciones.clientes;
  const integraciones = [
    ['Recepción de copias · n8n', !ingesta ? 'Sin recepciones' : ingesta.retrasada ? 'Recepción retrasada' : ingesta.ok === null ? 'Recepción en curso' : Number(ingesta.ok) === 0 ? 'Última recepción con errores' : 'Recepción al día', ingesta?.fecha, !ingesta || ingesta.ok === null || Number(ingesta.ok) === 0 || ingesta.retrasada],
    ['Clientes', !sync ? 'Sin sincronizaciones' : sync.ok === null ? 'Sincronización en curso' : Number(sync.ok) === 1 ? 'Última sincronización correcta' : 'Última sincronización con errores', sync?.fecha, !sync || Number(sync.ok) !== 1],
    ['Monitorización', `${valor(resumen?.agentes)} agentes · ${valor(resumen?.sin_informe)} sin informar >24 h`, 'La ausencia de informes no confirma una desconexión.', Number(resumen?.sin_informe) > 0],
  ];
  return <section className="dashboard-page" aria-labelledby="resumen-titulo" aria-busy={cargando}>
    <PageHeading id="resumen-titulo" eyebrow="Sauber Ofimática" title="Monitoreo de copias y equipos"
      description="Estado de las copias de seguridad y los equipos de los clientes."
      actions={<div className="dashboard-refresh"><span>{datos ? `Actualizado a las ${new Date(datos.actualizado).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}` : 'Esperando datos'}</span>
        <button className="btn sec" disabled={cargando} onClick={() => { setCargando(true); setRevision((n) => n + 1); }}><Icon name="refresh" size={15} />{cargando ? 'Actualizando…' : 'Actualizar'}</button></div>} />
    {error && <div className="errbox" role="alert">{error}. {datos ? 'Se mantienen los últimos datos recibidos.' : 'No se pudo cargar el resumen.'} <button className="btn sec" onClick={() => setRevision((n) => n + 1)}>Reintentar</button></div>}
    <div className="dashboard-health" role="group" aria-label="Estado general de equipos, copias e integraciones">
    <div className="dashboard-metrics" aria-label="Indicadores de todos los equipos">
      {metricas.map(([titulo, numero, nota, key, tono]) => <button key={key} type="button" className={`dashboard-metric tone-${tono}`}
        onClick={() => onNavigate(`/equipos?estado=${key === 'asignar' ? 'sin-asignar' : key}`)}>
        <span>{titulo}<Icon name="arrow" size={14} /></span><strong>{valor(numero)}</strong><small>{nota}</small>
      </button>)}
    </div>
    <div className="dashboard-context"><span><strong>{valor(resumen?.total_equipos)}</strong> equipos registrados</span><span><strong>{valor(resumen?.agentes)}</strong> con agente de monitorización</span><span><strong>{valor(equipos.reduce((n, e) => n + Number(e.copias_en_curso), 0))}</strong> copias en curso</span></div>
    <div className="dashboard-overview">
      <section className="dashboard-panel" aria-labelledby="copias-resumen-titulo">
        <div className="dashboard-panel-heading"><div><span className="eyebrow">Actividad reciente</span><h2 id="copias-resumen-titulo">Copias · últimas 24 horas</h2></div><a href={rutaHref('/copias')} onClick={(e) => navegar(e, '/copias')}>Ver historial ↗</a></div>
        <div className="backup-headline"><strong>{datos && totalCopias ? `${Math.round((copias.OK || 0) / totalCopias * 100)}%` : '—'}</strong><span>copias correctas<small>{valor(totalCopias)} resultados recibidos</small></span></div>
        <div className="backup-distribution" aria-label={`${valor(totalCopias)} copias recibidas en las últimas 24 horas`}>
          {ESTADOS.filter(([key]) => copias[key] > 0).map(([key, label, tono]) => <span key={key} className={`backup-segment ${tono}`} style={{ flexGrow: copias[key] }} title={`${label}: ${copias[key]}`} />)}
        </div>
        <div className="backup-counts">{ESTADOS.map(([key, label, tono]) => <div key={key}><span className={`status-dot ${tono}`} /><strong>{valor(copias[key])}</strong><span>{label}</span></div>)}</div>
        {datos && !totalCopias && <p className="dashboard-muted">No se han recibido copias en las últimas 24 horas.</p>}
      </section>
      <section className="dashboard-panel" aria-labelledby="integraciones-titulo">
        <div className="dashboard-panel-heading"><div><span className="eyebrow">Flujo de datos</span><h2 id="integraciones-titulo">Integraciones</h2></div></div>
        <div className="integration-list">{integraciones.map(([nombre, estado, fecha, aviso]) => <div className="integration-row" key={nombre}>
          <span className={`status-dot ${!datos ? 'pending' : aviso ? 'warning' : 'ok'}`} /><div><strong>{nombre}</strong><span>{datos ? estado : 'Cargando estado…'}</span></div><small>{datos ? fecha || 'Sin datos' : '—'}</small>
        </div>)}</div>
      </section>
    </div>
    </div>
    <section className="dashboard-panel dashboard-queue" aria-labelledby="cola-titulo">
      <div className="dashboard-panel-heading"><div><span className="eyebrow">Seguimiento operativo</span><h2 id="cola-titulo">Equipos que requieren atención</h2><p>Hardware crítico primero; después, servidores con fallos o copias ausentes.</p></div><span className="queue-count">{valor(equipos.filter(conError).length)} con incidencias</span></div>
      <div className="queue-filters"><label><span className="sr-only">Buscar equipo o cliente</span><input type="search" placeholder="Buscar equipo, cliente o software…" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} /></label>
        <label><span className="sr-only">Filtrar actividad</span><select value={filtro} onChange={(e) => setFiltro(e.target.value)}>{FILTROS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
        {(busqueda || filtro !== 'todos') && <button className="btn sec" onClick={() => { setBusqueda(''); setFiltro('todos'); }}>Limpiar</button>}
        <span>{filas.length} resultados</span>
      </div>
      {!filas.length && <div className="empty-state" role="status">{!datos ? cargando ? 'Cargando equipos…' : 'Datos no disponibles' : equipos.length ? 'No hay equipos que coincidan con los filtros.' : 'No hay incidencias ni copias activas en este momento.'}</div>}
      <div className="dashboard-table-wrap" hidden={!filas.length}><table className="dashboard-table"><thead><tr><th>Prioridad</th><th>Equipo / cliente</th><th>Errores Windows</th><th>Errores copias</th><th>Último evento o alerta</th><th><span className="sr-only">Acciones</span></th></tr></thead><tbody>
        {pagina.items.map((e) => <tr key={e.id}><td><span className={`queue-priority level-${prioridad(e)}`}>{['En curso', 'Atención', 'Alta', 'Crítica'][prioridad(e)]}</span></td>
          <td><a href={rutaHref(destino(e))} onClick={(event) => navegar(event, destino(e))}>{e.monitor_alias || e.nombre}</a><small>{e.cliente || 'Sin asignar'}{e.tipo === 'servidor' ? ' · Servidor' : ''}</small></td>
          <td><div className="queue-badges">
            {Number(e.eventos_windows) > 0 && <span className="queue-badge error">{e.eventos_windows} {Number(e.eventos_windows) === 1 ? 'evento' : 'eventos'} de Windows</span>}
            {Number(e.alertas_hardware) > 0 && <span className="queue-badge error">{e.alertas_hardware} {Number(e.alertas_hardware) === 1 ? 'alerta crítica' : 'alertas críticas'} de hardware</span>}
            {!Number(e.eventos_windows) && !Number(e.alertas_hardware) && <span className="equipos-muted">Sin errores Windows</span>}
          </div></td><td><div className="queue-badges">
            {Number(e.errores_pendientes) > 0 && <span className="queue-badge error">{e.errores_pendientes} {Number(e.errores_pendientes) === 1 ? 'fallo' : 'fallos'} registrados</span>}
            {e.sin_copia && <span className="queue-badge warning">Error crítico: copia no recibida</span>}
            {Number(e.copias_en_curso) > 0 && <span className="queue-badge running">{e.copias_en_curso} en curso</span>}
            {!Number(e.errores_pendientes) && !e.sin_copia && !Number(e.copias_en_curso) && <span className="equipos-muted">Sin errores de copias</span>}
          </div></td><td className="queue-event">{e.errores_evento?.[0]?.titulo || '—'}</td><td><a className="queue-open" href={rutaHref(destino(e))} onClick={(event) => navegar(event, destino(e))} aria-label={`Revisar ${e.nombre}`}>Revisar ↗</a></td></tr>)}
      </tbody></table></div>
      <Paginacion total={filas.length} pagina={pagina.page} paginas={pagina.pageCount} porPagina={pagina.pageSize} onCambiar={pagina.setPage} etiqueta="equipos" />
      <p className="dashboard-queue-note">Los fallos de copia son los errores conservados. Los eventos abarcan 30 días en modo estándar y todo lo disponible en auditoría completa; las alertas de hardware son las activas.</p>
    </section>
  </section>;
}
