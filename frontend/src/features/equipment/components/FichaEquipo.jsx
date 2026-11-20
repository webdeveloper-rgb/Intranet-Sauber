import { useEffect, useState } from 'react';
import Icon from '../../../shared/ui/Icon';

const TABS = [['resumen', 'Resumen', 'inicio'], ['copias', 'Copias', 'copias'],
  ['monitor', 'Monitorización', 'equipos'], ['eventos', 'Eventos', 'eventos'], ['configuracion', 'Configuración', 'settings']];

/** Organiza las funciones existentes sin perder los borradores al cambiar de pestaña. */
export default function FichaEquipo({ equipo, pestana = 'resumen', onCambiar, resumen, copias, monitor, configuracion,
  eventos, datosMonitor, cargandoMonitor, errorMonitor }) {
  const actual = TABS.some(([key]) => key === pestana) ? pestana : 'resumen';
  const [visitadas, setVisitadas] = useState([actual]);
  useEffect(() => { setVisitadas((prev) => prev.includes(actual) ? prev : [...prev, actual]); }, [actual]);
  const alertas = datosMonitor?.alertas || [];
  const errores = Number(equipo.errores_pendientes || 0);
  const eventosWindows = Number(datosMonitor?.eventos_windows || 0);
  const teclado = (event, index) => {
    let siguiente;
    if (event.key === 'ArrowRight') siguiente = (index + 1) % TABS.length;
    else if (event.key === 'ArrowLeft') siguiente = (index - 1 + TABS.length) % TABS.length;
    else if (event.key === 'Home') siguiente = 0;
    else if (event.key === 'End') siguiente = TABS.length - 1;
    if (siguiente === undefined) return;
    event.preventDefault();
    onCambiar(TABS[siguiente][0]);
    event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[siguiente]?.focus();
  };
  const sinAgente = <div className="empty-state"><strong>Sin datos de monitorización</strong>
    <span>Aún no se ha recibido un informe del agente para este equipo.</span></div>;
  const contenido = {
    resumen: <>
      {resumen}
      <div className="ficha-estado-grid">
        <section className="ficha-card" aria-labelledby="ficha-copias-estado">
          <div className="ficha-card-title"><Icon name="copias" /><h3 id="ficha-copias-estado">Copias de seguridad</h3></div>
          <p className={errores ? 'ficha-estado-error' : ''}>{errores ? `${errores} ${errores === 1 ? 'fallo registrado' : 'fallos registrados'}`
            : equipo.tiene_copias ? 'Sin fallos de copia registrados' : 'Sin copias recibidas'}</p>
          <span className="ficha-muted">{equipo.error_reviewed_at ? `Última revisión: ${equipo.error_reviewed_at}` : 'Consulta el historial y revisa los avisos recibidos.'}</span>
          <button className="btn sec" type="button" onClick={() => onCambiar('copias')}>Ver copias y revisión <Icon name="arrow" size={14} /></button>
        </section>
        <section className="ficha-card" aria-labelledby="ficha-monitor-estado">
          <div className="ficha-card-title"><Icon name="equipos" /><h3 id="ficha-monitor-estado">Monitorización del equipo</h3></div>
          <p>{cargandoMonitor ? 'Consultando estado…' : errorMonitor ? 'Estado no disponible' : !equipo.tiene_monitor ? 'Sin informes del agente'
            : !datosMonitor?.monitor ? 'Informe no disponible' : alertas.length ? `${alertas.length} ${alertas.length === 1 ? 'alerta activa' : 'alertas activas'}` : 'Sin alertas de hardware activas'}</p>
          <span className="ficha-muted">Discos, capacidad disponible y especificaciones del último informe.</span>
          <button className="btn sec" type="button" onClick={() => onCambiar('monitor')}>Ver monitorización <Icon name="arrow" size={14} /></button>
        </section>
        <section className="ficha-card" aria-labelledby="ficha-windows-estado">
          <div className="ficha-card-title"><Icon name="eventos" /><h3 id="ficha-windows-estado">Eventos de Windows</h3></div>
          <p className={eventosWindows ? 'ficha-estado-error' : ''}>{!equipo.tiene_monitor ? 'Sin agente de monitorización'
            : cargandoMonitor ? 'Consultando eventos…' : eventosWindows ? `${eventosWindows} ${eventosWindows === 1 ? 'error detectado' : 'errores detectados'}` : 'Sin errores de Windows detectados'}</p>
          <span className="ficha-muted">Errores y eventos críticos recibidos desde el agente de este equipo.</span>
          <button className="btn sec" type="button" onClick={() => onCambiar('eventos')} disabled={!equipo.tiene_monitor}>Ver eventos Windows <Icon name="arrow" size={14} /></button>
        </section>
      </div>
      {errorMonitor && <div className="errbox" role="alert">No se pudo consultar la monitorización: {errorMonitor}</div>}
      {alertas.length > 0 && <section className="ficha-card" aria-labelledby="ficha-alertas-titulo">
        <div className="ficha-card-title"><Icon name="eventos" /><h3 id="ficha-alertas-titulo">Alertas de hardware</h3></div>
        <ul className="ficha-alertas">{alertas.map((alerta, i) => <li key={alerta.id || i}>
          <span className={`queue-priority ${alerta.severidad === 'critical' ? 'level-3' : 'level-2'}`}>{alerta.severidad === 'critical' ? 'Crítica' : 'Advertencia'}</span>
          <div><strong>{alerta.titulo}</strong>{alerta.detalle && <p>{alerta.detalle}</p>}</div>
        </li>)}</ul>
      </section>}
    </>,
    copias,
    monitor: equipo.tiene_monitor ? <>{monitor}{!datosMonitor?.monitor && !cargandoMonitor && !errorMonitor && sinAgente}</> : sinAgente,
    eventos: equipo.tiene_monitor ? eventos : <div className="empty-state"><strong>Sin eventos del agente</strong><span>Los eventos de Windows estarán disponibles cuando el equipo envíe informes de monitorización.</span></div>,
    configuracion: <div className="ficha-configuracion">{configuracion}</div>,
  };
  return <div className="ficha-equipo">
    <div className="ficha-tabs" role="tablist" aria-label="Secciones de la ficha del equipo">
      {TABS.map(([key, label, icon], index) => <button type="button" key={key} role="tab" id={`ficha-tab-${key}`}
        aria-selected={actual === key} aria-controls={`ficha-panel-${key}`} tabIndex={actual === key ? 0 : -1}
        onKeyDown={(event) => teclado(event, index)} onClick={() => onCambiar(key)}>
        <Icon name={icon} size={17} />{label}
        {key === 'copias' && errores > 0 && <span className="ficha-tab-count">{errores}</span>}
      </button>)}
    </div>
    {TABS.map(([key]) => <div key={key} role="tabpanel" id={`ficha-panel-${key}`} aria-labelledby={`ficha-tab-${key}`}
      className="ficha-tab-panel" hidden={actual !== key} tabIndex={0}>
      {(key === 'eventos' ? actual === key : actual === key || visitadas.includes(key)) && contenido[key]}
    </div>)}
  </div>;
}
