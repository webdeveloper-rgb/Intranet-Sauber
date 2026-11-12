const TEXTOS = {
  copias: ['Panel de Control de Copias', 'ERROR pasa a EN REVISIÓN al describir el arreglo y a OK al cerrar la vigilancia'],
  clientes: ['Asignación de equipos', ''],
  servidores: ['Servidores', ''],
};
const NAV = [['copias', 'Copias'], ['clientes', 'Asignar equipos'], ['servidores', 'Servidores']];

export default function Header({ nombre, vista, onVista, esAdmin, onCuenta }) {
  const [titulo, subtitulo] = TEXTOS[vista] || TEXTOS.copias;
  return (
    <header>
      <div className="logo"><img src="assets/logo.png" alt="Sauber Ofimática" /></div>
      <div className="titulo">
        <h1>{titulo}</h1>
        {subtitulo && <p>{subtitulo}</p>}
      </div>
      <div className="top">
        <span>{nombre}</span>
        {nombre && NAV.filter(([k]) => k !== vista).map(([k, t]) => (
          <button className="linkbtn" key={k} onClick={() => onVista(k)}>{t}</button>
        ))}
        {nombre && <button className="linkbtn" onClick={onCuenta}>{esAdmin ? 'Tokens' : 'Mi cuenta'}</button>}
        <a href="logout.php">Salir</a>
      </div>
    </header>
  );
}
