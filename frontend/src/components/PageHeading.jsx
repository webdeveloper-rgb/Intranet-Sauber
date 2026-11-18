/** Encabezado común de las secciones principales del panel. */
export default function PageHeading({ id, eyebrow, title, description, actions, className = '' }) {
  return <div className={`page-heading ${className}`.trim()}>
    <div className="page-heading-content">
      {eyebrow && <span className="eyebrow">{eyebrow}</span>}
      <h2 id={id}>{title}</h2>
      {description && <p>{description}</p>}
    </div>
    {actions && <div className="page-heading-actions">{actions}</div>}
  </div>;
}
