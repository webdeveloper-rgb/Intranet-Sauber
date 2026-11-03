import { memo } from 'react';
import Tag from './Tag';
import { ef, vencido, atrasado } from '../logic';

const Fila = memo(function Fila({ c, onOpen, r, h }) {
  const k = ef(c);
  const notas = (c.notas || []).length;
  return (
    <tr className={k === 'ERR' ? 'err' : k === 'REV' ? 'pen' : ''} onClick={() => onOpen(c.id)}>
      <td className="nowrap">{c.fecha}</td>
      <td>{c.cliente}</td>
      <td>{c.equipo}</td>
      <td>
        <Tag c={c} />
        {notas > 0 && <span className="nb" title="Tiene notas de seguimiento">📝 {notas}</span>}
        {vencido(c) && <span className="nb al" title="Recordatorio vencido">⏰ Vencido</span>}
        {atrasado(c, h) && <span className="nb al" title="Error sin revisar a tiempo">⚠ +{h} h</span>}
        {r > 1 && c.estado === 'ERROR' && <span className="nb wa" title="Errores repetidos en este equipo">🔁 {r}</span>}
      </td>
      <td className="c-desc">{c.desc_error || '—'}</td>
      <td className="c-fix">{c.revisado ? c.revision_desc : '—'}</td>
      <td className="c-rev">{c.estado === 'ERROR' ? (c.revisado ? '✔' : '✖') : '—'}</td>
    </tr>
  );
});

export default function Tabla({ rows, cargando, onOpen, rep, horas, claveEquipo }) {
  return (
    <div className="wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Estado</th>
            <th className="c-desc">Descripción error</th>
            <th className="c-fix">Descripción del arreglo</th>
            <th className="c-rev">Rev.</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((c) => (
            <Fila key={c.id} c={c} onOpen={onOpen} r={rep[claveEquipo(c)] || 0} h={horas} />
          ))}
          {rows.length === 0 && (
            <tr><td colSpan="7" className="vacio">{cargando ? 'Cargando…' : 'Sin resultados'}</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
}
