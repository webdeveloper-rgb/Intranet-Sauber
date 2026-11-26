import { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';

export default function NombreEquipoEditor({ equipo, admin, onSaved }) {
  const [nombre, setNombre] = useState(equipo.nombre || '');
  const [guardando, setGuardando] = useState(false);
  const [estado, setEstado] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    setNombre(equipo.nombre || '');
    setEstado('');
    setError('');
  }, [equipo.id, equipo.nombre]);

  const guardar = async (event) => {
    event.preventDefault();
    const valor = nombre.trim();
    if (!valor || valor === equipo.nombre) return;
    setGuardando(true);
    setEstado('');
    setError('');
    try {
      const result = await api('equipo_nombre', { id: equipo.id, nombre: valor });
      setNombre(result.nombre);
      onSaved(equipo.id, result.nombre);
      setEstado('Nombre del equipo actualizado.');
    } catch (e) {
      setError(e.message || 'No se pudo cambiar el nombre del equipo.');
    } finally {
      setGuardando(false);
    }
  };

  if (!admin) return null;
  return <form className="equipo-alias-editor" onSubmit={guardar}>
    <label htmlFor={`nombre-equipo-${equipo.id}`}>Nombre del equipo</label>
    <div>
      <input id={`nombre-equipo-${equipo.id}`} value={nombre} maxLength={200}
        placeholder="Nombre exacto que llegará en las copias" disabled={guardando}
        onChange={(event) => { setNombre(event.target.value); setEstado(''); }} />
      <button className="btn sec" type="submit" disabled={guardando || !nombre.trim() || nombre.trim() === equipo.nombre}>
        {guardando ? 'Guardando…' : 'Cambiar nombre'}
      </button>
    </div>
    <span className={`equipo-alias-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'}>
      {error || estado || (equipo.nombre_manual ? 'Nombre fijado manualmente.' : 'El agente puede actualizar el nombre si cambia el hostname.')}
    </span>
  </form>;
}
