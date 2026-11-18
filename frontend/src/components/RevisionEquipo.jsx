/** Marca a nivel de equipo que los errores actuales se han revisado. */
import { useState } from 'react';
import { api } from '../api';
import { MIN_PALABRAS, palabras } from '../logic';

export default function RevisionEquipo({ equipo, onSaved }) {
  const [descripcion, setDescripcion] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState('');
  const total = Number(equipo.errores_pendientes || 0);
  const revisado = Boolean(equipo.error_reviewed_at);
  if (!total && !revisado) return null;

  const guardar = async (event) => {
    event.preventDefault();
    setGuardando(true);
    setError('');
    try {
      const result = await api('equipo_revisar_errores', { id: equipo.id, desc: descripcion.trim() });
      onSaved(equipo.id, result);
      setDescripcion('');
    } catch (e) {
      setError(e.message || 'No se pudo guardar la revisión del equipo.');
    } finally {
      setGuardando(false);
    }
  };

  return <section className="equipo-error-revision" aria-labelledby="titulo-revision-equipo">
    <h3 id="titulo-revision-equipo">Revisión de errores del equipo</h3>
    {revisado ? <>
      <p>Revisado por {equipo.error_reviewed_by} el {equipo.error_reviewed_at}. Al llegar una nueva copia OK se eliminarán definitivamente {total} errores anteriores.</p>
      {equipo.error_review_desc && <blockquote>{equipo.error_review_desc}</blockquote>}
      {total > 0 && <form onSubmit={guardar}>
        <label htmlFor={`revision-equipo-${equipo.id}`}>Actualizar la revisión</label>
        <textarea id={`revision-equipo-${equipo.id}`} rows="3" value={descripcion}
          onChange={(event) => setDescripcion(event.target.value)} placeholder="Describe lo revisado y las acciones realizadas." />
        <button className="btn sec" type="submit" disabled={palabras(descripcion) < MIN_PALABRAS || guardando}>
          {guardando ? 'Guardando…' : 'Actualizar revisión'}
        </button>
      </form>}
    </> : <>
      <p>Hay {total} errores de copia en este equipo. Revisa el equipo; cuando llegue una nueva copia OK, esos errores se borrarán definitivamente.</p>
      <form onSubmit={guardar}>
        <label htmlFor={`revision-equipo-${equipo.id}`}>Qué se ha revisado o corregido</label>
        <textarea id={`revision-equipo-${equipo.id}`} rows="3" value={descripcion}
          onChange={(event) => setDescripcion(event.target.value)} placeholder="Describe la revisión del equipo y las acciones realizadas." />
        <div className="equipo-error-revision-acciones">
          <span>{palabras(descripcion)}/{MIN_PALABRAS} palabras mínimo</span>
          <button className="btn" type="submit" disabled={palabras(descripcion) < MIN_PALABRAS || guardando}>
            {guardando ? 'Guardando…' : 'Marcar equipo revisado'}
          </button>
        </div>
      </form>
    </>}
    {error && <p className="equipo-alias-estado error" role="alert">{error}</p>}
  </section>;
}
