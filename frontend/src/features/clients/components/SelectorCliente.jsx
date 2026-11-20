/**
 * Selector accesible de cliente con busqueda y presentacion de codigo/nombre.
 */
import { useEffect, useMemo, useState } from 'react';
import { norm } from '../../../shared/lib/logic';

const etiqueta = (cliente) => cliente
  ? `${cliente.nombre || '(sin nombre)'}${cliente.codigo ? ` · ${cliente.codigo}` : ''} · ${cliente.tiene_satbk && cliente.tiene_sat18 ? 'SATBK · SAT18' : cliente.tiene_satbk ? 'SATBK' : cliente.tiene_sat18 ? 'SAT18' : 'Sin SAT'}`
  : '';

export default function SelectorCliente({ clientes, value, onChange, disabled = false, id, mostrarFiltros = true }) {
  const [abierto, setAbierto] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [filtroSatbk, setFiltroSatbk] = useState('satbk');
  const [activo, setActivo] = useState(0);
  const seleccionado = clientes.find((c) => Number(c.id) === Number(value));
  const totalConSatbk = useMemo(() => clientes.filter((c) => c.tiene_satbk).length, [clientes]);
  const totalConSat18 = useMemo(() => clientes.filter((c) => c.tiene_sat18).length, [clientes]);
  const totalConSat = useMemo(() => clientes.filter((c) => c.tiene_satbk || c.tiene_sat18).length, [clientes]);
  const opciones = useMemo(() => {
    const q = norm(busqueda.trim());
    return clientes.filter((c) => {
      if (filtroSatbk === 'satbk' && !c.tiene_satbk) return false;
      if (filtroSatbk === 'sat18' && !c.tiene_sat18) return false;
      if (filtroSatbk === 'ambos' && !(c.tiene_satbk && c.tiene_sat18)) return false;
      return !q || norm(etiqueta(c)).includes(q);
    });
  }, [clientes, busqueda, filtroSatbk]);

  useEffect(() => {
    setFiltroSatbk('satbk');
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
    {mostrarFiltros && <div className="selector-cliente-filtros" role="group" aria-label="Elegir listado de clientes">
      <span>Listado</span>
      <button type="button" className={filtroSatbk === 'satbk' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'satbk'} disabled={disabled || !totalConSatbk}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('satbk')}>
        Con SATBK <span>{totalConSatbk}</span>
      </button>
      <button type="button" className={filtroSatbk === 'sat18' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'sat18'} disabled={disabled || !totalConSat18}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('sat18')}>
        Con SAT18 <span>{totalConSat18}</span>
      </button>
      <button type="button" className={filtroSatbk === 'ambos' ? 'activo' : ''}
        aria-pressed={filtroSatbk === 'ambos'} disabled={disabled || !totalConSat}
        onMouseDown={(e) => e.preventDefault()} onClick={() => cambiarFiltroSatbk('ambos')}>
        Ambos <span>{totalConSat}</span>
      </button>
    </div>}
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
