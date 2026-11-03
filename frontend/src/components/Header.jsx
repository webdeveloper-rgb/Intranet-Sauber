export default function Header({ nombre, rol, onUsuarios }) {
  return (
    <header>
      <div className="logo"><img src="assets/logo.png" alt="Sauber Ofimática" /></div>
      <div className="titulo">
        <h1>Panel de Control de Copias</h1>
        <p>ERROR → EN REVISIÓN (al describir el arreglo) → OK (al cerrar la vigilancia)</p>
      </div>
      <div className="top">
        <span>👤 {nombre}</span>
        {nombre && <button className="linkbtn" onClick={onUsuarios}>{rol === 'admin' ? '👥 Usuarios' : '🔑 Mi cuenta'}</button>}
        <a href="logout.php">Salir</a>
      </div>
    </header>
  );
}
