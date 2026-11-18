export default function FiltrosActivos({ filtros = [], onQuitar, onLimpiar }) {
  if (!filtros.length) return null;

  return <div className="filtros-activos" aria-label="Filtros activos">
    <span className="filtros-activos-titulo">Filtros activos</span>
    {filtros.map(({ clave, etiqueta }) => <button type="button" key={clave}
      className="filtro-activo-chip" onClick={() => onQuitar(clave)}
      aria-label={`Quitar filtro: ${etiqueta}`} title={`Quitar filtro: ${etiqueta}`}>
      {etiqueta}<span aria-hidden="true">{'\u00d7'}</span>
    </button>)}
    <button type="button" className="filtros-limpiar" onClick={onLimpiar}>Limpiar todos</button>
  </div>;
}
