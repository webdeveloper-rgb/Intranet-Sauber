/**
 * Tabla/lista de copias, incluida navegacion accesible al detalle.
 */
import { memo } from 'react';
import Tag from './Tag';
import { ef, atrasado, etiquetaTipo } from '../logic';
import { rutaHref } from '../routes';

const Fila = memo(function Fila({ c, onOpen, r, h }) {
  const k = ef(c);
  const notas = (c.notas || []).length;
  const sinCopia = c.codigo === 'SIN_COPIA';
  const abrir = () => !sinCopia && onOpen(c.id);
  return (
    <tr className={`${k === 'ERR' ? 'err' : k === 'REV' ? 'pen' : k === 'INC' ? 'por-clasificar' : ''}${sinCopia ? ' sin-copia' : ''}`}
      tabIndex={sinCopia ? undefined : 0}
      aria-label={sinCopia ? undefined : `Abrir detalle de la copia de ${c.equipo}, ${c.fecha}`}
      onClick={abrir}
      onKeyDown={(e) => {
        if (!sinCopia && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          abrir();
        }
      }}>
      <td>{sinCopia ? c.fecha : <a className="copia-fila-enlace" href={rutaHref(`/copias/${c.id}`)}
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); abrir(); }}>{c.fecha}</a>}</td>
      <td>{c.cliente}</td>
      <td>{c.equipo}</td>
      <td>{etiquetaTipo(c)}</td>
      <td className="estado-celda">
        <Tag c={c} />
        {notas > 0 && <span className="nb" title="Tiene notas de seguimiento">Notas: {notas}</span>}
        {c.codigo === 'SIN_COPIA' && <span className="nb al" title="No ha llegado ninguna copia de este equipo">Sin copia recibida</span>}
        {atrasado(c, h) && <span className="nb al" title="Error sin revisar a tiempo">Atrasado: {h} h</span>}
        {!sinCopia && r > 1 && c.estado === 'ERROR' && <span className="nb wa" title="Errores repetidos en este equipo">Repetidos: {r}</span>}
      </td>
      <td className="c-desc">{c.desc_error || '—'}</td>    </tr>
  );
});

export default function Tabla({ rows, cargando, error = '', onOpen, rep, horas, claveEquipo, sinCopia = false }) {
  return (
    <div className="wrap">
      {sinCopia ? (
        <table className="sin-copia-table">
          <thead>
            <tr>
              <th>Cliente</th><th>Equipo</th><th>Tipo</th><th>Última copia</th><th>Día omitido</th><th>Aviso desde</th><th>Detalle</th><th>Estado</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c) => (
              <tr className="err" key={c.id}>
                <td>{c.cliente}</td>
                <td>{c.equipo}</td>
                <td>{etiquetaTipo(c)}</td>
                <td>{c.ultima_copia || 'Nunca'}</td>
                <td>{c.fecha_esperada || '—'}</td>
                <td>{c.fecha}</td>
                <td>{c.desc_error || 'No llegó una copia en el último día programado.'}</td>
                <td><Tag c={c} /></td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr><td colSpan="8" className="vacio">{cargando ? 'Cargando…' : error ? 'No se pudo comprobar si hay equipos sin copia.' : 'No hay equipos sin copia recibida.'}</td></tr>
            )}
          </tbody>
        </table>
      ) : (
      <table>
        <thead>
          <tr>
            <th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Tipo</th><th>Estado</th>
            <th className="c-desc">Detalle del resultado</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((c) => (
            <Fila key={c.id} c={c} onOpen={onOpen} r={rep[claveEquipo(c)] || 0} h={horas} />
          ))}
          {rows.length === 0 && (
            <tr><td colSpan="6" className="vacio">{cargando ? 'Cargando…' : error ? 'No se pudo comprobar si hay resultados.' : 'No hay resultados para estos filtros.'}</td></tr>
          )}
        </tbody>
      </table>
      )}
    </div>
  );
}
