export default function Header({ nombre, rol, vista, onVista, onUsuarios }) {
  const enClientes = vista === 'clientes';
  return (
    <header>
      <div className="logo"><img src="assets/logo.png" alt="Sauber Ofimática" /></div>
      <div className="titulo">
        <h1>{enClientes ? 'Asignación de equipos' : 'Panel de Control de Copias'}</h1>
        <p>{enClientes
          ? 'Revisa los equipos detectados y vincúlalos con el cliente correspondiente.'
          : 'ERROR → EN REVISIÓN (al describir el arreglo) → OK (al cerrar la vigilancia)'}</p>
      </div>
      <div className="top">
        <span>👤 {nombre}</span>
        {nombre && (
          <button className="linkbtn" onClick={() => onVista(enClientes ? 'copias' : 'clientes')}>
            {enClientes ? '📋 Copias' : '🖥️ Asignar equipos'}
          </button>
        )}
        {nombre && <button className="linkbtn" onClick={onUsuarios}>{rol === 'admin' ? '👥 Usuarios' : '🔑 Mi cuenta'}</button>}
        <a href="logout.php">Salir</a>
      </div>
    </header>
  );
}
