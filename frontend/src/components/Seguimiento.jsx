import { vencido } from '../logic';
import { descargarICS } from '../ics';

const ESTADOS = [
  ['vigilancia', 'En vigilancia · En revisión'],
  ['cerrado', 'Cerrado · pasa a OK'],
];

export default function Seguimiento({ c, onSeg }) {
  return (
    <div className="seg">
      <b>Estado de seguimiento</b>
      <div className="rf" style={{ marginTop: 8 }}>
        <div className="bar" style={{ margin: 0 }}>
          {ESTADOS.map(([k, t]) => (
            <button key={k} className={c.seg === k ? 'on' : ''}
              onClick={() => onSeg(c.id, k === 'cerrado' ? { seg: k, recordar: null } : { seg: k })}>
              {t}
            </button>
          ))}
        </div>
        {c.seg === 'vigilancia' && (
          <label>Recordar el{' '}
            <input type="date" value={c.recordar || ''}
              onChange={(e) => onSeg(c.id, { recordar: e.target.value || null })} />
          </label>
        )}
        {c.seg === 'vigilancia' && c.recordar && (
          <button className="btn sec" onClick={() => descargarICS(c)}>Añadir al calendario de Windows</button>
        )}
      </div>
      {vencido(c) && <div className="errbox">El recordatorio ha vencido: toca volver a revisar este equipo.</div>}
    </div>
  );
}
