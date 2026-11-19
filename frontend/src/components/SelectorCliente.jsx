/**
 * Selector accesible de cliente con busqueda y presentacion de codigo/nombre.
 */
import { useEffect, useMemo, useState } from 'react';
import { norm } from '../logic';

const etiqueta = (cliente) => cliente
  ? `${cliente.nombre || '(sin nombre)'}${cliente.codigo ? ` · ${cliente.codigo}` : ''} · ${cliente.tiene_satbk ? 'SATBK' : 'Sin SATBK'}`
  : '';

export default function SelectorCliente({ clientes, value, onChange, disabled = false, id }) {
  const [abierto, setAbierto] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [filtroSatbk, setFiltroSatbk] = useState('todos');
  const [activo, setActivo] = useState(0);
  const seleccionado = clientes.find((c) => Number(c.id) === Number(value));
  const totalConSatbk = useMemo(() => clientes.filter((c) => c.tiene_satbk).length, [clientes]);
  const totalSinSatbk = clientes.length - totalConSatbk;
  const opciones = useMemo(() => {
    const q = norm(busqueda.trim());
    return clientes.filter((c) => {
      if (filtroSatbk === 'con' && !c.tiene_satbk) return false;
      if (filtroSatbk === 'sin' && c.tiene_satbk) return false;
      return !q || norm(etiqueta(c)).includes(q);
    });
  }, [clientes, busqueda, filtroSatbk]);

  useEffect(() => {
    setFiltroSatbk('todos');
    setBusqueda('');
    setActivo(0);
  }, [id]);

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

  const cambiarFiltroSatbk = (filtro) => {
    setFiltroSatbk(filtro);
    setBusqueda('');
    setActivo(0);
    setAbierto(true);
  };

  return <div className="selector-cliente">
    <div className="selector-cliente-filtros" role="group" aria-label="Elegir listado de clientes">
      <span>Listado</span>
      <button type="button" className={filtroSatbk === 'todos' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'todos'} disabled={disabled}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('todos')}>
        Todos <span>{clientes.length}</span>
      </button>
      <button type="button" className={filtroSatbk === 'con' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'con'} disabled={disabled || !totalConSatbk}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('con')}>
        Con SATBK <span>{totalConSatbk}</span>
      </button>
      <button type="button" className={filtroSatbk === 'sin' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'sin'} disabled={disabled || !totalSinSatbk}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('sin')}>
        Sin SATBK <span>{totalSinSatbk}</span>
      </button>
    </div>
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
