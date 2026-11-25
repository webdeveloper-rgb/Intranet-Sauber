import { TIPOS } from '../../../shared/lib/logic';
import { unir } from '../lib/clientes';

export function TarjetasClientes({ items, equiposPorCliente, mostrarPendientes, equiposSinAsignar, equiposClienteInactivo, onAbrir }) {
  return <div className="cliente-grid">
    {items.map((cliente) => {
      const asignados = equiposPorCliente.get(Number(cliente.id)) || [];
      const servidores = asignados.filter((equipo) => equipo.tipo === 'servidor').length;
      return <button type="button" className="cliente-tarjeta" key={cliente.id}
        onClick={() => onAbrir(`/clientes/${cliente.id}`)}>
        <span className="cliente-tarjeta-flecha">›</span>
        <span className="cliente-tarjeta-titulo">
          <strong>{cliente.nombre}</strong>
          <span className={`cliente-satbk${cliente.tiene_satbk || cliente.tiene_sat18 ? ' activo' : ''}`}>
            <span className="cliente-satbk-punto" aria-hidden="true" />
            {cliente.tiene_satbk && cliente.tiene_sat18 ? 'SATBK · SAT18' : cliente.tiene_satbk ? 'SATBK' : cliente.tiene_sat18 ? 'SAT18' : 'Sin SAT'}
          </span>
        </span>
        <span className="cliente-conteo">{asignados.length} equipos · {servidores} servidores</span>
      </button>;
    })}
    {mostrarPendientes && equiposSinAsignar.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
      onClick={() => onAbrir('/clientes/sin-asignar')}>
      <span className="cliente-tarjeta-flecha">›</span>
      <strong>Sin asignar</strong>
      <span className="cliente-conteo">{equiposSinAsignar.length} equipos por vincular</span>
    </button>}
    {mostrarPendientes && equiposClienteInactivo.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
      onClick={() => onAbrir('/clientes/cliente-inactivo')}>
      <span className="cliente-tarjeta-flecha">›</span>
      <strong>Cliente no activo</strong>
      <span className="cliente-conteo">{equiposClienteInactivo.length} equipos conservados</span>
    </button>}
  </div>;
}

export function ListaEquiposCliente({ equipos, clienteId, onAbrir }) {
  return <div className="equipo-lista-navegable">
    {equipos.map((equipo) => (
      <article className="equipo-navegable" key={equipo.id}>
        <button type="button" className="equipo-navegable-abrir"
          onClick={() => onAbrir(`/clientes/${encodeURIComponent(String(clienteId))}/equipos/${equipo.id}`)}>
          <span className="equipo-navegable-info">
            <strong>{equipo.nombre}{equipo.monitor_alias && <small className="equipo-alias-lista"> · {equipo.monitor_alias}</small>}</strong>
            <span>{unir(equipo.software, equipo.programa || TIPOS[equipo.tipo], equipo.fecha_creacion ? `Alta ${equipo.fecha_creacion}` : null)}</span>
            <span className="equipo-navegable-estados">
              <span className={`estado-chip${equipo.tiene_copias ? ' ok' : ''}`}>{equipo.tiene_copias ? 'Copias' : 'Sin copias'}</span>
              <span className={`estado-chip${equipo.tiene_monitor ? ' ok' : ''}`}>{equipo.tiene_monitor ? 'Agente' : 'Sin agente'}</span>
            </span>
          </span>
          <span className="equipo-tipo-pill">{equipo.tipo === 'servidor' ? 'Servidor' : 'Equipo'}</span>
          <span className="equipo-tarjeta-flecha">›</span>
        </button>
      </article>
    ))}
  </div>;
}
