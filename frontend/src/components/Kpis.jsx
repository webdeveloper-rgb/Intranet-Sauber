export default function Kpis({ n, total }) {
  const items = [
    ['er', n.p, 'Errores'],
    ['wa', n.w, 'En revisión'],
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
