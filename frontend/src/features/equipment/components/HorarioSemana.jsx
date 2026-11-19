import { useState, useEffect } from 'react';
import { api } from '../../../shared/api/client';

const DIAS_SEMANA = [
  ['L', 'Lunes'], ['M', 'Martes'], ['X', 'Miércoles'], ['J', 'Jueves'],
  ['V', 'Viernes'], ['S', 'Sábado'], ['D', 'Domingo'],
];

export default function HorarioSemana({ equipo, onSaved }) {
  const horarioActual = /^[01]{7}$/.test(equipo.dias_copia || '') && equipo.dias_copia.includes('1')
    ? equipo.dias_copia : '1111111';
  const [dias, setDias] = useState(horarioActual);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState('');
  const [guardado, setGuardado] = useState(false);

  useEffect(() => {
    setDias(horarioActual);
    setError('');
  }, [equipo.id, horarioActual]);

  const cambiar = (indice, marcado) => {
    const seleccion = dias.split('');
    if (!marcado && seleccion.filter((dia) => dia === '1').length === 1) {
      setError('Selecciona al menos un día.');
      setGuardado(false);
      return;
    }
    seleccion[indice] = marcado ? '1' : '0';
    setDias(seleccion.join(''));
    setError('');
    setGuardado(false);
  };

  const guardar = async () => {
    setGuardando(true);
    setError('');
    setGuardado(false);
    try {
      await api('equipo_horario', { id: equipo.id, dias_copia: dias });
      onSaved(equipo.id, dias);
      setGuardado(true);
    } catch (e) {
      setError(e.message);
    } finally {
      setGuardando(false);
    }
  };

  return <div className="equipo-horario">
    <fieldset disabled={guardando}>
      <legend>Días esperados para las copias</legend>
      <div className="equipo-dias">
        {DIAS_SEMANA.map(([abreviatura, nombre], indice) => (
          <label className={dias[indice] === '1' ? 'seleccionado' : ''} key={nombre} title={nombre}>
            <input type="checkbox" aria-label={nombre} checked={dias[indice] === '1'}
              onChange={(e) => cambiar(indice, e.target.checked)} />
            <span aria-hidden="true">{abreviatura}</span>
          </label>
        ))}
      </div>
    </fieldset>
    <button className="btn sec equipo-horario-guardar" disabled={guardando || dias === horarioActual}
      onClick={guardar}>{guardando ? 'Guardando…' : 'Guardar días'}</button>
    <span className={`equipo-horario-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'} aria-live="polite">
      {error || (guardando ? 'Guardando horario…' : guardado ? 'Horario guardado.' : '')}
    </span>
  </div>;
}

