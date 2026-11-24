import { norm } from '../../../shared/lib/logic';

export const unir = (...valores) => valores.filter(Boolean).join(' · ');

export function sugerirCliente(clientes, sugerido) {
  const busqueda = norm(String(sugerido).trim());
  if (!busqueda) return undefined;

  const exacto = clientes.find((cliente) => [cliente.nombre, cliente.codigo, unir(cliente.nombre, cliente.codigo)]
    .some((valor) => valor && norm(String(valor)) === busqueda));
  if (exacto) return exacto;
  if (busqueda.length < 4) return undefined;

  const parciales = clientes.filter((cliente) => {
    const nombre = norm(String(cliente.nombre || ''));
    return nombre.length >= 4 && (nombre.includes(busqueda) || busqueda.includes(nombre));
  });
  return parciales.length === 1 ? parciales[0] : undefined;
}

/** Indexa equipos una vez para evitar recorrer todo el parque por cada cliente. */
export function agruparEquiposPorCliente(equipos) {
  const porCliente = new Map();
  equipos.forEach((equipo) => {
    if (equipo.cliente_id == null) return;
    const clienteId = Number(equipo.cliente_id);
    if (!porCliente.has(clienteId)) porCliente.set(clienteId, []);
    porCliente.get(clienteId).push(equipo);
  });
  return porCliente;
}
