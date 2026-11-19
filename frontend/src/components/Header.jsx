import { useEffect, useRef, useState } from 'react';
import { rutaHref } from '../routes';
import Icon from './Icon';

const NAV = [['inicio', 'Resumen'], ['clientes', 'Clientes'], ['copias', 'Copias'], ['eventos', 'Eventos'], ['informes', 'Informes']];
const navPath = (key) => key === 'inicio' ? '/' : `/${key}`;

export default function Header({ nombre, vista, onNavegar, esAdmin, onCuenta, onNotificaciones, onVolver, mostrarVolver = false }) {
  const [abierto, setAbierto] = useState(false);
  const toggle = useRef(null);
  useEffect(() => { setAbierto(false); }, [vista]);
  useEffect(() => {
    const cerrar = (event) => {
      if (event.key === 'Escape' && abierto) { setAbierto(false); toggle.current?.focus(); }
    };
    window.addEventListener('keydown', cerrar);
    return () => window.removeEventListener('keydown', cerrar);
  }, [abierto]);
  const navegar = (event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    setAbierto(false);
    onNavegar(path);
  };
  return <>
    {abierto && <button className="sidebar-overlay" aria-label="Cerrar navegación" onClick={() => setAbierto(false)} />}
    <aside className={`dashboard-sidebar${abierto ? ' is-open' : ''}`} id="dashboard-navigation" aria-label="Panel Sauber">
      <a className="sidebar-logo" href={rutaHref('/')} onClick={(event) => navegar(event, '/')}>
        <img src="assets/logo.png" alt="Sauber Ofimática, ir al resumen" />
      </a>
      <span className="sidebar-caption">ESPACIO DE TRABAJO</span>
      <nav className="sidebar-nav" aria-label="Navegación principal">
        {nombre && NAV.map(([key, label]) => <a key={key} href={rutaHref(navPath(key))}
          aria-current={vista === key ? 'page' : undefined} onClick={(event) => navegar(event, navPath(key))}>
          <Icon name={key} />{label}<span className="nav-active-dot" />
        </a>)}
      </nav>
      <div className="sidebar-bottom">
        <span className="sidebar-caption">{esAdmin ? 'ADMINISTRACIÓN' : 'PERSONAL'}</span>
        {nombre && <button type="button" onClick={() => { setAbierto(false); onCuenta(); }}><Icon name="settings" /> {esAdmin ? 'Tokens y cuenta' : 'Mi cuenta'}</button>}
        <a href="logout.php"><Icon name="logout" />Cerrar sesión</a>
        <small>Sauber · Intranet</small>
      </div>
    </aside>
    <header className="app-header dashboard-topbar">
      <div className="dashboard-topbar-title">
        <button ref={toggle} type="button" className="sidebar-toggle btn sec" aria-label={abierto ? 'Cerrar menú' : 'Abrir menú'}
          aria-expanded={abierto} aria-controls="dashboard-navigation" onClick={() => setAbierto(!abierto)}><Icon name="menu" /></button>
        {nombre && mostrarVolver && <button className="topbar-back" type="button" onClick={onVolver}>
          <Icon name="arrow" className="back-arrow" size={17} />Volver
        </button>}
        <span>Intranet <span className="topbar-divider">/</span> <strong>{NAV.find(([key]) => key === vista)?.[1] || 'Resumen'}</strong></span>
      </div>
      <div className="dashboard-topbar-actions">
        {nombre && <button className="topbar-notifications" aria-label="Notificaciones" type="button" onClick={onNotificaciones}><Icon name="bell" size={20} /></button>}
        {nombre && <button className="topbar-account" type="button" onClick={onCuenta} aria-label={`Abrir cuenta de ${nombre}`}>
          <span className="user-avatar" aria-hidden="true">{nombre.trim().slice(0, 1).toUpperCase()}</span><span>{nombre}</span>
        </button>}
      </div>
    </header>
  </>;
}
