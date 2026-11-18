import FiltrosActivos from './FiltrosActivos';

/** Barra común para presentar los filtros de cada vista con la misma estructura. */
export default function BarraFiltros({
  id,
  title = 'Filtros',
  description,
  search,
  fields = [],
  count,
  summary,
  actions,
  activeFilters = [],
  onRemoveFilter,
  onClear,
  onSubmit,
  children,
  className = '',
}) {
  const Container = onSubmit ? 'form' : 'section';
  return <Container id={id} className={`filter-bar ${className}`.trim()}
    aria-label={title || 'Filtros'} onSubmit={onSubmit}>
    {title && <div className="filter-bar-heading">
      <h3>{title}</h3>
      {description && <p>{description}</p>}
    </div>}
    <div className="filter-bar-controls">
      {search && <label className="filter-bar-search" htmlFor={search.id}>
        <span>{search.label || 'Buscar'}</span>
        <input id={search.id} type="search" value={search.value} placeholder={search.placeholder || ''}
          onChange={search.onChange} aria-label={search.ariaLabel} />
      </label>}
      {fields.map((field) => <label className={field.className || ''} key={field.id} htmlFor={field.id}>
        <span>{field.label}</span>
        {field.type === 'select' ? <select id={field.id} className={field.controlClassName || ''} value={field.value} onChange={field.onChange}
          disabled={field.disabled} aria-label={field.ariaLabel}>
          {field.options.map((option) => {
            const [value, label] = Array.isArray(option) ? option : [option.value, option.label];
            return <option key={value} value={value}>{label}</option>;
          })}
        </select> : <input id={field.id} className={field.controlClassName || ''} type={field.type || 'text'} value={field.value}
          min={field.min} max={field.max} placeholder={field.placeholder} onChange={field.onChange}
          disabled={field.disabled} aria-label={field.ariaLabel} />}
      </label>)}
      {actions && <div className="filter-bar-actions">{actions}</div>}
    </div>
    {children && <div className="filter-bar-extras">{children}</div>}
    {(count !== undefined || summary) && <div className="filter-bar-footer">
      {summary && <div className="filter-bar-summary">{summary}</div>}
      {count !== undefined && <span className="filter-bar-count">{count}</span>}
    </div>}
    {!!activeFilters.length && <FiltrosActivos filtros={activeFilters} onQuitar={onRemoveFilter} onLimpiar={onClear} />}
  </Container>;
}
