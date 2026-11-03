export default function Header({ nombre, rol, vista, onVista, onUsuarios }) {
  const enClientes = vista === 'clientes';
  return (
    <header>
      <div className="logo"><img src="assets/logo.png" alt="Sauber Ofimática" /></div>
      <div className="titulo">
        <h1>{enClientes ? 'Clientes y máquinas' : 'Panel de Control de Copias'}</h1>
        <p>{enClientes
          ? 'Datos sincronizados desde la API. Pulsa un cliente para ver sus máquinas.'
          : 'ERROR → EN REVISIÓN (al describir el arreglo) → OK (al cerrar la vigilancia)'}</p>
      </div>
      <div className="top">
        <span>👤 {nombre}</span>
        {nombre && (
          <button className="linkbtn" onClick={() => onVista(enClientes ? 'copias' : 'clientes')}>
            {enClientes ? '📋 Copias' : '🏢 Clientes'}
          </button>
        )}
        {nombre && <button className="linkbtn" onClick={onUsuarios}>{rol === 'admin' ? '👥 Usuarios' : '🔑 Mi cuenta'}</button>}
        <a href="logout.php">Salir</a>
      </div>
    </header>
  );
}