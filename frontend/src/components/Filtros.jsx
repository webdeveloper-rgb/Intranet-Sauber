import { FILTROS, EXTRA, ALERTA } from '../logic';

export default function Filtros({ filtro, setFiltro, n, f, setF, resultados }) {
  const { cli, desde, hasta, horas } = f;
  const hayFiltros = cli || desde || hasta;
  return (
    <>
      <div className="bar">
        {FILTROS.map(([k, t]) => {
          const cls = (filtro === k ? 'on' : '') + (ALERTA[k] && n[EXTRA[k]] > 0 && filtro !== k ? ' al' : '');
          return (
            <button key={k} className={cls} onClick={() => setFiltro(k)}>
              {t}{EXTRA[k] ? ` (${n[EXTRA[k]]})` : ''}
            </button>
          );
        })}
      </div>
      <div className="fil">
        <input type="search" placeholder="Buscar cliente…" aria-label="Buscar cliente" value={cli}
          onChange={(e) => setF({ cli: e.target.value })} />
        <label>Desde <input type="date" value={desde} max={hasta || undefined}
          onChange={(e) => setF({ desde: e.target.value })} /></label>
        <label>Hasta <input type="date" value={hasta} min={desde || undefined}
          onChange={(e) => setF({ hasta: e.target.value })} /></label>
        {hayFiltros && (
          <button className="btn sec" onClick={() => setF({ cli: '', desde: '', hasta: '' })}>Limpiar</button>
        )}
        <span className="cnt">{resultados} resultado{resultados === 1 ? '' : 's'}</span>
        <label>Avisar errores tras{' '}
          <select value={horas} onChange={(e) => setF({ horas: +e.target.value })}>
            {[12, 24, 48, 72].map((h) => <option key={h} value={h}>{h} h</option>)}
          </select>
        </label>
      </div>
    </>
  );
}
