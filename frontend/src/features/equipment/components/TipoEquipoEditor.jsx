import { useState, useEffect } from 'react';
import { api } from '../../../shared/api/client';

export default function TipoEquipoEditor({ equipo, admin, onSaved }) {
  const [tipo, setTipo] = useState(equipo.tipo_manual ? equipo.tipo : 'auto');
  const [guardando, setGuardando] = useState(false);
  const [estado, setEstado] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    setTipo(equipo.tipo_manual ? equipo.tipo : 'auto');
    setEstado('');
    setError('');
  }, [equipo.id, equipo.tipo, equipo.tipo_manual]);

  const guardar = async () => {
    setGuardando(true);
    setEstado('');
    setError('');
    try {
      const result = await api('equipo_tipo', { id: equipo.id, tipo });
      onSaved(equipo.id, result.tipo || null, result.tipo_manual || null);
      setEstado(tipo === 'auto' ? 'Tipo automático restablecido.' : 'Tipo de equipo guardado.');
    } catch (e) {
      setError(e.message || 'No se pudo guardar el tipo de equipo.');
    } finally {
      setGuardando(false);
    }
  };

  if (!admin) return null;
  const tipoActual = equipo.tipo_manual ? equipo.tipo : 'auto';
  return <div className="equipo-tipo-editor">
    <label htmlFor={`tipo-equipo-${equipo.id}`}>Tipo de equipo (independiente del alias)</label>
    <div>
      <select id={`tipo-equipo-${equipo.id}`} value={tipo} disabled={guardando} onChange={(event) => { setTipo(event.target.value); setEstado(''); }}>
        <option value="auto">Automático por nombre</option>
        <option value="usuario">Equipo de usuario</option>
        <option value="servidor">Servidor</option>
        <option value="programa">Equipo de programa</option>
      </select>
      <button type="button" className="btn sec" disabled={guardando || tipo === tipoActual} onClick={guardar}>
        {guardando ? 'Guardando…' : 'Guardar tipo'}
      </button>
    </div>
    <span className={`equipo-alias-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'}>
      {error || estado || (equipo.tipo_manual ? 'Tipo fijado manualmente; las próximas copias conservarán esta elección.' : 'El sistema clasifica este equipo según su nombre.')}
    </span>
  </div>;
}

