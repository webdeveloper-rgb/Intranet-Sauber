/** Encabezado común de las secciones principales del panel. */
export default function PageHeading({ id, eyebrow, title, description, actions, className = '', level = 1 }) {
  const Heading = level === 2 ? 'h2' : 'h1';
  return <div className={`page-heading ${className}`.trim()}>
    <div className="page-heading-content">
      {eyebrow && <span className="eyebrow">{eyebrow}</span>}
      <Heading id={id}>{title}</Heading>
      {description && <p>{description}</p>}
    </div>
    {actions && <div className="page-heading-actions">{actions}</div>}
  </div>;
}
