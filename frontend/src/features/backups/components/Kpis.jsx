/**
 * Resumen visual de cantidades de copias en la vista actual.
 */
export default function Kpis({ n, total }) {
  const items = [
    ['er', n.p, 'Fallos confirmados'],
    ['er critico', n.crit, 'Críticos'],
    ['er grave', n.grave, 'Graves'],
    ['wa', n.wa, 'Advertencias'],
    ['run', n.run, 'En curso'],
    ['inc', n.inc, 'Pendientes clasificación'],
    ['ok', n.o, 'Correctas'],
    ['', total, 'Total'],
  ];
  return (
    <div className="kpis">
      {items.map(([k, v, t]) => (
        <div key={t} className={'kpi ' + k}>
          <b>{v}</b>
          <span>{t}</span>
        </div>
      ))}
    </div>
  );
}
