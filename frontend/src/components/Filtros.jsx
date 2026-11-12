/**
 * Controles de filtro y rango temporal de la lista de copias.
 */
import { FILTROS, EXTRA, ALERTA, PROGRAMAS } from '../logic';

export default function Filtros({ filtro, setFiltro, n, f, setF, resultados }) {
  const { cli, tipo, desde, hasta, horas } = f;
  const hayFiltros = cli || tipo || desde || hasta;
  const filtroAlerta = ALERTA[filtro] && n[EXTRA[filtro]] > 0;
  const alertas = (n.sc || 0) + (n.v || 0) + (n.a || 0);
  return (
      <div className="fil">
        <label className="fil-estado" htmlFor="filtro-copias">
          <span>Estado</span>
          <select id="filtro-copias" aria-label="Filtrar copias por estado"
          className={filtroAlerta ? 'al' : ''}
          value={filtro} onChange={(e) => setFiltro(e.target.value)}>
            {FILTROS.map(([k, t]) => (
              <option key={k} value={k}>
                {t}{EXTRA[k] ? ` (${n[EXTRA[k]]})` : ''}
              </option>
            ))}
          </select>
        </label>
        <span className={`aviso-cuenta${alertas ? ' activa' : ''}`}
          title={`Avisos: ${n.sc || 0} sin copia, ${n.v || 0} recordatorios vencidos y ${n.a || 0} errores atrasados`}
          aria-label={`Avisos: ${alertas}`}>
          Avisos <strong>{alertas}</strong>
        </span>
        <input type="search" placeholder="Buscar cliente…" aria-label="Buscar cliente" value={cli}
          onChange={(e) => setF({ cli: e.target.value })} />
        <label>Tipo{' '}
          <select value={tipo} onChange={(e) => setF({ tipo: e.target.value })}>
            <option value="">Todos</option>
            <option value="usuario">Usuarios</option>
            <option value="servidor">Servidores</option>
            <option value="programa">Programas (todos)</option>
            {PROGRAMAS.map((p) => <option key={p} value={'programa:' + p}>{p}</option>)}
          </select>
        </label>
        <label>Desde <input type="date" value={desde} max={hasta || undefined}
          onChange={(e) => setF({ desde: e.target.value })} /></label>
        <label>Hasta <input type="date" value={hasta} min={desde || undefined}
          onChange={(e) => setF({ hasta: e.target.value })} /></label>
        {hayFiltros && (
          <button className="btn sec" onClick={() => setF({ cli: '', tipo: '', desde: '', hasta: '' })}>Limpiar</button>
        )}
        <span className="cnt">{resultados} resultado{resultados === 1 ? '' : 's'}</span>
        <label>Avisar errores tras{' '}
          <select value={horas} onChange={(e) => setF({ horas: +e.target.value })}>
            {[12, 24, 48, 72].map((h) => <option key={h} value={h}>{h} h</option>)}
          </select>
        </label>
      </div>
  );
}