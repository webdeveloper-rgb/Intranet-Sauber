import { memo } from 'react';
import Tag from './Tag';
import { ef, vencido, atrasado, etiquetaTipo } from '../logic';

const Fila = memo(function Fila({ c, onOpen, r, h }) {
  const k = ef(c);
  const notas = (c.notas || []).length;
  const sinCopia = c.codigo === 'SIN_COPIA';
  return (
    <tr className={`${k === 'ERR' ? 'err' : k === 'REV' ? 'pen' : ''}${sinCopia ? ' sin-copia' : ''}`}
      onClick={sinCopia ? undefined : () => onOpen(c.id)}>
      <td>{c.fecha}</td>
      <td>{c.cliente}</td>
      <td>{c.equipo}</td>
      <td>{etiquetaTipo(c)}</td>
      <td className="estado-celda">
        <Tag c={c} />
        {notas > 0 && <span className="nb" title="Tiene notas de seguimiento">Notas: {notas}</span>}
        {vencido(c) && <span className="nb al" title="Recordatorio vencido">Recordatorio vencido</span>}
        {c.codigo === 'SIN_COPIA' && <span className="nb al" title="No ha llegado ninguna copia de este equipo">Sin copia recibida</span>}
        {atrasado(c, h) && <span className="nb al" title="Error sin revisar a tiempo">Atrasado: {h} h</span>}
        {!sinCopia && r > 1 && c.estado === 'ERROR' && <span className="nb wa" title="Errores repetidos en este equipo">Repetidos: {r}</span>}
      </td>
      <td className="c-desc">{c.desc_error || '—'}</td>
      <td className="c-fix">{c.revisado ? c.revision_desc : '—'}</td>
      <td className="c-rev">{c.estado === 'ERROR' ? (c.revisado ? 'Sí' : 'No') : '—'}</td>
    </tr>
  );
});

export default function Tabla({ rows, cargando, onOpen, rep, horas, claveEquipo, sinCopia = false }) {
  return (
    <div className="wrap">
      {sinCopia ? (
        <table className="sin-copia-table">
          <thead>
            <tr>
              <th>Cliente</th><th>Equipo</th><th>Tipo</th><th>Última copia</th><th>Aviso desde</th><th>Estado</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c) => (
              <tr className="err" key={c.id}>
                <td>{c.cliente}</td>
                <td>{c.equipo}</td>
                <td>{etiquetaTipo(c)}</td>
                <td>{c.ultima_copia || 'Nunca'}</td>
                <td>{c.fecha}</td>
                <td><Tag c={c} /></td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr><td colSpan="6" className="vacio">{cargando ? 'Cargando…' : 'No hay equipos sin copia recibida.'}</td></tr>
            )}
          </tbody>
        </table>
      ) : (
      <table>
        <thead>
          <tr>
            <th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Tipo</th><th>Estado</th>
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
            <tr><td colSpan="8" className="vacio">{cargando ? 'Cargando…' : 'Sin resultados'}</td></tr>
          )}
        </tbody>
      </table>
      )}
    </div>
  );
}