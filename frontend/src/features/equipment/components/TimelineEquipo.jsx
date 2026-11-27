import React from 'react';

const iconoEstado = (estado) => {
  if (estado === 'OK') return '✓';
  if (estado === 'ERROR') return '✕';
  if (estado === 'WARNING') return '!';
  if (estado === 'RUNNING') return '↻';
  return '·';
};

export default function TimelineEquipo({ items = [] }) {
  const ordenados = [...items].sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)));
  if (!ordenados.length) {
    return <div className="mapeo-vacio">Sin actividad registrada para este equipo.</div>;
  }
  return (
    <ol className="timeline-equipo" aria-label="Línea de tiempo del equipo">
      {ordenados.map((item) => (
        <li key={item.id} className={`timeline-equipo__item timeline-equipo__item--${(item.estado || '').toLowerCase()}`}>
          <span className="timeline-equipo__icon" aria-hidden="true">{iconoEstado(item.estado)}</span>
          <div className="timeline-equipo__body">
            <div className="timeline-equipo__head">
              <strong>{item.estado || 'Sin estado'}</strong>
              <time>{item.fecha}</time>
            </div>
            {item.maquina_virtual && <small className="timeline-equipo__vm">VM: {item.maquina_virtual}</small>}
            {item.tarea && <small className="timeline-equipo__tarea">{item.tarea}</small>}
            {item.desc_error && <p className="timeline-equipo__error">{item.codigo ? `${item.codigo} · ` : ''}{item.desc_error}</p>}
          </div>
        </li>
      ))}
    </ol>
  );
}