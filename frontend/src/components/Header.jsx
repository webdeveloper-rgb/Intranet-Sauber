import { rutaHref } from '../routes';

const TEXTOS = {
  inicio: ['Inicio', 'Resumen de errores de copias y eventos que requieren atención'],
  copias: ['Copias', 'Historial de resultados y seguimiento de las copias recibidas'],
  clientes: ['Clientes y equipos', 'Asignación, estado y configuración de los equipos'],
  eventos: ['Eventos de Windows', 'Eventos recogidos y clasificados por nivel e impacto'],
  informes: ['Informes', 'Historial exportable para auditorías'],
};
const NAV = [['inicio', 'Inicio'], ['copias', 'Copias'], ['clientes', 'Clientes'], ['eventos', 'Eventos'], ['informes', 'Informes']];

export default function Header({ nombre, vista, onNavegar, esAdmin, onCuenta, onNotificaciones }) {
  const [titulo, subtitulo] = TEXTOS[vista] || TEXTOS.inicio;
  const navegar = (event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    onNavegar(path);
  };
  return (
    <header className="app-header">
      <div className="header-bar">
        <a className="logo" href={rutaHref('/inicio')} aria-label="Sauber Ofimática, ir al inicio"
          onClick={(event) => navegar(event, '/inicio')}>
          <img src="assets/logo.png" alt="Sauber Ofimática" />
        </a>
        <div className="header-page-title"><h1>{titulo}</h1><p>{subtitulo}</p></div>
        <nav className="primary-nav" aria-label="Navegación principal">
          {nombre && NAV.map(([key, label]) => <a className="linkbtn" key={key} href={rutaHref(`/${key}`)}
            aria-current={vista === key ? 'page' : undefined} onClick={(event) => navegar(event, `/${key}`)}>{label}</a>)}
        </nav>
        <div className="header-actions">
          {nombre && <span className="header-user">{nombre}</span>}
          {nombre && <button className="linkbtn" type="button" onClick={onNotificaciones}>Notificaciones</button>}
          {nombre && <button className="linkbtn" type="button" onClick={onCuenta}>{esAdmin ? 'Tokens' : 'Mi cuenta'}</button>}
          <a className="header-logout" href="logout.php">Salir</a>
        </div>
      </div>
    </header>
  );
}
