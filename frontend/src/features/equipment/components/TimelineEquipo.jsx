import React from 'react';

const estadoConfig = (estado) => {
  const valor = String(estado || '').toUpperCase();
  if (valor === 'OK') return { icono: '✓', etiqueta: 'OK', clase: 'ok' };
  if (valor === 'ERROR') return { icono: '✕', etiqueta: 'Error', clase: 'error' };
  if (valor === 'WARNING') return { icono: '!', etiqueta: 'Advertencia', clase: 'warning' };
  if (valor === 'RUNNING') return { icono: '↻', etiqueta: 'En curso', clase: 'running' };
  if (valor === 'REVISION') return { icono: '?', etiqueta: 'Revisión', clase: 'revision' };
  return { icono: '·', etiqueta: valor || 'Sin estado', clase: 'neutral' };
};

const formatearFecha = (valor) => {
  if (!valor) return '';
  const fecha = new Date(valor);
  if (Number.isNaN(fecha.getTime())) return String(valor);
  return fecha.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

export default function TimelineEquipo({ items = [] }) {
  const ordenados = [...items].sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)));
  if (!ordenados.length) {
    return <div className="mapeo-vacio">Sin actividad registrada para este equipo.</div>;
  }
  return (
    <ol className="timeline-equipo" aria-label="Línea de tiempo del equipo">
      {ordenados.map((item, indice) => {
        const config = estadoConfig(item.estado);
        return (
          <li key={item.id || indice} className={`timeline-equipo__item timeline-equipo__item--${config.clase}`}>
            <span className="timeline-equipo__marker" aria-hidden="true">
              <span className="timeline-equipo__dot">{config.icono}</span>
            </span>
            <article className="timeline-equipo__card">
              <header className="timeline-equipo__head">
                <span className={`timeline-equipo__estado timeline-equipo__estado--${config.clase}`}>{config.etiqueta}</span>
                <time className="timeline-equipo__fecha" dateTime={item.fecha}>{formatearFecha(item.fecha)}</time>
              </header>
              {(item.maquina_virtual || item.tarea) && (
                <div className="timeline-equipo__meta">
                  {item.maquina_virtual && <span className="timeline-equipo__vm">VM: {item.maquina_virtual}</span>}
                  {item.tarea && <span className="timeline-equipo__tarea">{item.tarea}</span>}
                </div>
              )}
              {item.desc_error && (
                <p className="timeline-equipo__error">
                  {item.codigo ? <strong>{item.codigo} · </strong> : null}
                  {item.desc_error}
                </p>
              )}
            </article>
          </li>
        );
      })}
    </ol>
  );
}