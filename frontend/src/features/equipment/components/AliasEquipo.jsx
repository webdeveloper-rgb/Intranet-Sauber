import { useState, useEffect } from 'react';
import { api } from '../../../shared/api/client';

export default function AliasEquipo({ equipo, admin, onSaved }) {
  const [alias, setAlias] = useState(equipo.monitor_alias || '');
  const [guardandoAlias, setGuardandoAlias] = useState(false);
  const [estado, setEstado] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    setAlias(equipo.monitor_alias || '');
    setEstado('');
    setError('');
  }, [equipo.id, equipo.monitor_alias]);

  const guardar = async (event) => {
    event.preventDefault();
    setGuardandoAlias(true);
    setEstado('');
    setError('');
    try {
      const result = await api('equipo_alias', { id: equipo.id, alias: alias.trim() });
      const nuevoAlias = result.alias || '';
      setAlias(nuevoAlias);
      onSaved(equipo.id, nuevoAlias);
      setEstado(nuevoAlias ? 'Alias guardado.' : 'Alias eliminado.');
    } catch (e) {
      setError(e.message || 'No se pudo guardar el alias.');
    } finally {
      setGuardandoAlias(false);
    }
  };

  if (!admin) return null;
  return <form className="equipo-alias-editor" onSubmit={guardar}>
    <label htmlFor={`alias-equipo-${equipo.id}`}>Alias (se muestra junto al nombre original)</label>
    <div>
      <input id={`alias-equipo-${equipo.id}`} value={alias} maxLength={100}
        placeholder="Ej.: Recepción, Caja 1…" disabled={guardandoAlias}
        onChange={(event) => { setAlias(event.target.value); setEstado(''); }} />
      <button className="btn sec" type="submit" disabled={guardandoAlias || alias.trim() === (equipo.monitor_alias || '')}>
        {guardandoAlias ? 'Guardando…' : 'Guardar alias'}
      </button>
    </div>
    <span className={`equipo-alias-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'}>{error || estado}</span>
  </form>;
}

