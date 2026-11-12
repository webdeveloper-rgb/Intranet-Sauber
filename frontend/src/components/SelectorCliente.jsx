/**
 * Selector accesible de cliente con busqueda y presentacion de codigo/nombre.
 */
import { useMemo, useState } from 'react';
import { norm } from '../logic';

const etiqueta = (cliente) => cliente ? `${cliente.nombre || '(sin nombre)'}${cliente.codigo ? ` · ${cliente.codigo}` : ''}` : '';

export default function SelectorCliente({ clientes, value, onChange, disabled = false, id }) {
  const [abierto, setAbierto] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [activo, setActivo] = useState(0);
  const seleccionado = clientes.find((c) => Number(c.id) === Number(value));
  const opciones = useMemo(() => {
    const q = norm(busqueda.trim());
    return clientes.filter((c) => !q || norm(etiqueta(c)).includes(q));
  }, [clientes, busqueda]);

  const elegir = (cliente) => {
    onChange(String(cliente.id));
    setBusqueda('');
    setActivo(0);
    setAbierto(false);
  };

  const teclado = (e) => {
    if (e.key === 'Escape') { setAbierto(false); setBusqueda(''); }
    if (e.key === 'ArrowDown') { e.preventDefault(); setAbierto(true); setActivo((i) => Math.min(i + 1, opciones.length - 1)); }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActivo((i) => Math.max(i - 1, 0)); }
    if (e.key === 'Enter' && abierto && opciones[activo]) { e.preventDefault(); elegir(opciones[activo]); }
  };

  return <div className="selector-cliente">
    <input
      id={id}
      type="search"
      role="combobox"
      aria-label="Buscar cliente para asignar el equipo"
      aria-autocomplete="list"
      aria-expanded={abierto}
      aria-controls={`${id}-opciones`}
      placeholder="Buscar cliente por nombre o código…"
      value={abierto ? busqueda : etiqueta(seleccionado)}
      disabled={disabled}
      onFocus={() => { setBusqueda(''); setActivo(0); setAbierto(true); }}
      onBlur={() => window.setTimeout(() => setAbierto(false), 120)}
      onChange={(e) => { setBusqueda(e.target.value); setActivo(0); setAbierto(true); }}
      onKeyDown={teclado}
    />
    {abierto && !disabled && <div className="selector-resultados" id={`${id}-opciones`} role="listbox">
      {opciones.length ? opciones.map((cliente, i) => <button
        type="button"
        role="option"
        aria-selected={Number(cliente.id) === Number(value)}
        className={i === activo ? 'opcion-activa' : ''}
        key={cliente.id}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => elegir(cliente)}
      >{etiqueta(cliente)}</button>) : <p>No hay clientes que coincidan.</p>}
    </div>}
  </div>;
}
