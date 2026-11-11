import { FILTROS, EXTRA, ALERTA, PROGRAMAS } from '../logic';

export default function Filtros({ filtro, setFiltro, n, f, setF, resultados }) {
  const { cli, tipo, desde, hasta, horas } = f;
  const hayFiltros = cli || tipo || desde || hasta;
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
    </>
  );
}