import { rutaHref } from '../routes';

const NAV = [['inicio', 'Inicio'], ['copias', 'Copias'], ['clientes', 'Clientes'], ['eventos', 'Eventos'], ['informes', 'Informes']];
const navPath = (key) => key === 'inicio' ? '/' : `/${key}`;

export default function Header({ nombre, vista, onNavegar, esAdmin, onCuenta, onNotificaciones }) {
  const navegar = (event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    onNavegar(path);
  };
  return (
    <header className="app-header">
      <div className="header-bar">
        <a className="logo" href={rutaHref('/')} aria-label="Sauber Ofimática, ir al inicio"
          onClick={(event) => navegar(event, '/')}>
          <img src="assets/logo.png" alt="Sauber Ofimática" />
        </a>
        <nav className="primary-nav" aria-label="Navegación principal">
          {nombre && NAV.map(([key, label]) => <a className="linkbtn" key={key} href={rutaHref(navPath(key))}
            aria-current={vista === key ? 'page' : undefined} onClick={(event) => navegar(event, navPath(key))}>{label}</a>)}
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
