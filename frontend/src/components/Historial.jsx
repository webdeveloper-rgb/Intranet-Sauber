import Tag from './Tag';

export default function Historial({ c, hist, r }) {
  return (
    <div className="notas">
      <h3>Historial del equipo {c.equipo} ({hist.length + 1} copias)</h3>
      {r > 1 && <div className="errbox">🔁 Este equipo acumula {r} errores: puede haber un problema de fondo.</div>}
      {hist.length === 0
        ? <small className="mut">Sin otras copias registradas.</small>
        : hist.map((x) => (
            <div className="hrow" key={x.id}>
              <span>{x.fecha}</span>
              <Tag c={x} />
            </div>
          ))}
    </div>
  );
}
