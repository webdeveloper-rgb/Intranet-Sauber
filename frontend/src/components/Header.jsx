/**
 * Cabecera, navegacion principal y acceso a acciones de cuenta.
 */
import { rutaHref } from '../routes';

const TEXTOS = {
  copias: ['Panel de Control de Copias', 'ERROR pasa a EN REVISIÓN al describir el arreglo y a OK al cerrar la vigilancia'],
  clientes: ['Clientes y equipos', ''],
};
const NAV = [['copias', 'Copias'], ['clientes', 'Clientes']];

export default function Header({ nombre, vista, onNavegar, esAdmin, onCuenta }) {
  const [titulo, subtitulo] = TEXTOS[vista] || TEXTOS.copias;
  const navegar = (event, path) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    onNavegar(path);
  };
  return (
    <header>
      <div className="logo"><img src="assets/logo.png" alt="Sauber Ofimática" /></div>
      <div className="titulo">
        <h1>{titulo}</h1>
        {subtitulo && <p>{subtitulo}</p>}
      </div>
      <div className="top">
        <span>{nombre}</span>
        {nombre && NAV.map(([k, t]) => (
          <a className="linkbtn" key={k} href={rutaHref(`/${k}`)} aria-current={vista === k ? 'page' : undefined}
            onClick={(event) => navegar(event, `/${k}`)}>{t}</a>
        ))}
        {nombre && <button className="linkbtn" onClick={onCuenta}>{esAdmin ? 'Tokens' : 'Mi cuenta'}</button>}
        <a href="logout.php">Salir</a>
      </div>
    </header>
  );
}
