/**
 * Resumen visual de cantidades de copias en la vista actual.
 */
export default function Kpis({ n, total }) {
  const items = [
    ['er', n.p, 'Errores'],
    ['wa', n.inc, 'Por clasificar'],
    ['ok', n.o, 'OK'],
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
