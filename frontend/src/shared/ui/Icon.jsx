const icons = {
  equipos: 'fa-desktop',
  inicio: 'fa-table-cells-large',
  clientes: 'fa-users',
  copias: 'fa-database',
  eventos: 'fa-wave-square',
  informes: 'fa-file-lines',
  bell: 'fa-bell',
  settings: 'fa-gear',
  logout: 'fa-right-from-bracket',
  arrow: 'fa-arrow-right',
  refresh: 'fa-arrows-rotate',
  menu: 'fa-bars',
};

/** Decorative icons: their containing controls provide the accessible label. */
export default function Icon({ name, size = 18, className = '', style, ...props }) {
  return <i {...props} aria-hidden="true"
    className={`app-icon fa-solid ${icons[name] || icons.inicio} ${className}`.trim()}
    style={{ fontSize: size, width: size, height: size, ...style }} />;
}
