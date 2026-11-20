import { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import { MIN_PALABRAS, SEVERIDAD, palabras } from '../../../shared/lib/logic';

export default function RevisionEquipo({ equipo, onSaved }) {
  const [descripcion, setDescripcion] = useState('');
  const [errores, setErrores] = useState([]);
  const [totalErrores, setTotalErrores] = useState(Number(equipo.errores_pendientes || 0));
  const [cargando, setCargando] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState('');
  const revisado = Boolean(equipo.error_reviewed_at);

  useEffect(() => {
    let activo = true;
    if (!Number(equipo.errores_pendientes || 0)) {
      setErrores([]);
      setTotalErrores(0);
      return () => { activo = false; };
    }
    setCargando(true);
    api('equipo_errores', { equipo_id: equipo.id })
      .then((resultado) => {
        if (!activo) return;
        setErrores(resultado.errores || []);
        setTotalErrores(Number(resultado.total || 0));
      })
      .catch((e) => { if (activo) setError(e.message || 'No se pudieron cargar los errores.'); })
      .finally(() => { if (activo) setCargando(false); });
    return () => { activo = false; };
  }, [equipo.id, equipo.errores_pendientes]);

  if (!totalErrores && !equipo.error_reviewed_at) return null;

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
    <div className="equipo-error-revision-heading">
      <div><span className="eyebrow">Incidencias de copias</span>
        <h3 id="titulo-revision-equipo">Revisión de errores de {equipo.nombre}</h3></div>
      <strong className="equipo-error-revision-total">{totalErrores} {totalErrores === 1 ? 'error' : 'errores'}</strong>
    </div>
    {revisado
      ? <p className="equipo-error-revision-nota">Equipo revisado por {equipo.error_reviewed_by} el {equipo.error_reviewed_at}. Los errores de la lista se eliminarán cuando llegue una copia correcta de este equipo ({equipo.software}).</p>
      : <p>Revisa las incidencias de abajo. Al marcar el equipo como revisado, la siguiente copia correcta asociada a la ficha de <strong>{equipo.nombre}</strong> eliminará estos errores.</p>}

    <div className="equipo-error-lista" aria-live="polite">
      {cargando ? <p className="mut">Cargando errores…</p> : error ? null : errores.length ? errores.map((item) =>
        <article className="equipo-error-item" key={item.copia_id ? `c-${item.copia_id}` : `s-${item.staging_id}`}>
          <div className="equipo-error-item-meta"><time>{item.fecha}</time><span>{item.software || equipo.software}</span>
            <span>{SEVERIDAD[item.severidad || 'normal'] || SEVERIDAD.normal}</span>
            {item.codigo && <code>{item.codigo}</code>}{item.staging === 1 && <span className="equipo-error-pendiente">Pendiente de importar</span>}</div>
          <p>{item.desc_error || 'Error sin descripción.'}</p>
        </article>) : <p className="mut">No se pudieron encontrar filas de error para mostrar.</p>}
      {totalErrores > errores.length && <p className="mut">Se muestran {errores.length} de {totalErrores} errores; al llegar una copia correcta se resolverán los errores pendientes del equipo.</p>}
    </div>

    {revisado && equipo.error_review_desc && <blockquote>{equipo.error_review_desc}</blockquote>}
    <form onSubmit={guardar}>
      <label htmlFor={`revision-equipo-${equipo.id}`}>{revisado ? 'Actualizar las notas de revisión' : 'Qué se ha revisado o corregido'}</label>
      <textarea id={`revision-equipo-${equipo.id}`} rows="2" value={descripcion}
        onChange={(event) => setDescripcion(event.target.value)} placeholder="Describe brevemente la revisión realizada." />
      <div className="equipo-error-revision-acciones">
        {!revisado && <span>{palabras(descripcion)}/{MIN_PALABRAS} palabras mínimo</span>}
        <button className="btn" type="submit" disabled={palabras(descripcion) < MIN_PALABRAS || guardando}>
          {guardando ? 'Guardando…' : revisado ? 'Actualizar revisión' : 'Marcar equipo revisado'}
        </button>
      </div>
    </form>
    {error && <p className="equipo-alias-estado error" role="alert">{error}</p>}
  </section>;
}
