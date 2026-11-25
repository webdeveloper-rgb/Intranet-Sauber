import { useMemo, useState } from 'react';
import { api } from '../../../shared/api/client';
import { norm } from '../../../shared/lib/logic';

function detectarGrupos(equipos) {
  const porNombre = new Map();
  equipos.forEach((equipo) => {
    const nombre = norm(String(equipo.nombre || '').trim()).replace(/\s+/g, ' ');
    if (!nombre) return;
    if (!porNombre.has(nombre)) porNombre.set(nombre, []);
    porNombre.get(nombre).push(equipo);
  });

  const listos = [];
  const excluidos = [];
  porNombre.forEach((grupo) => {
    if (grupo.length < 2) return;
    const nombre = grupo[0].nombre;
    const clientes = [...new Set(grupo.map((item) => Number(item.cliente_id) || null).filter(Boolean))];
    const agentes = grupo.filter((item) => item.tiene_monitor);
    const razon = clientes.length > 1 ? 'Hay equipos asignados a clientes distintos.'
      : agentes.length > 1 ? 'Hay más de un agente; hay que elegir cuál conservar.'
        : grupo.length > 21 ? 'El grupo supera el límite de combinación.' : '';

    let destino = null;
    if (!razon) {
      const asignados = grupo.filter((item) => Number(item.cliente_id) > 0);
      const base = asignados.length ? asignados : grupo;
      if (base.length === 1) destino = base[0];
      else if (agentes.length === 1 && base.includes(agentes[0])) destino = agentes[0];
      else if (agentes.length === 1 && !asignados.length) destino = agentes[0];
      else {
        const conCopias = base.filter((item) => item.tiene_copias);
        if (conCopias.length === 1) destino = conCopias[0];
      }
    }

    if (razon) excluidos.push({ nombre, equipos: grupo, razon });
    else if (!destino) excluidos.push({ nombre, equipos: grupo, razon: 'No hay un destino claro para conservar la configuración.' });
    else listos.push({ nombre, equipos: grupo, destino, origenes: grupo.filter((item) => item.id !== destino.id),
      agenteId: agentes[0]?.id ?? null });
  });
  return { listos, excluidos };
}

export default function CombinarDuplicados({ equipos, onFinished }) {
  const { listos, excluidos } = useMemo(() => detectarGrupos(equipos), [equipos]);
  const [planes, setPlanes] = useState(null);
  const [confirmado, setConfirmado] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState('');
  const [resultado, setResultado] = useState('');

  const preparar = async () => {
    setOcupado(true); setError(''); setResultado(''); setPlanes(null); setConfirmado(false);
    const preparados = [];
    const omitidos = [...excluidos];
    for (const grupo of listos) {
      try {
        const plan = await api('equipo_combinar_prever', {
          destino_id: grupo.destino.id,
          origen_ids: grupo.origenes.map((item) => item.id),
        });
        if ((plan.agentes || []).length > 1) {
          omitidos.push({ ...grupo, razon: 'La revisión del servidor encontró varios agentes.' });
          continue;
        }
        preparados.push({ ...grupo, plan, agenteId: plan.agentes?.[0]?.equipo_id ?? null });
      } catch (e) {
        omitidos.push({ ...grupo, razon: e.message });
      }
    }
    setPlanes({ preparados, omitidos });
    setOcupado(false);
  };

  const combinar = async () => {
    if (!planes?.preparados.length || !confirmado) return;
    setOcupado(true); setError('');
    const hechos = [];
    const fallidos = [];
    for (const grupo of planes.preparados) {
      try {
        await api('equipo_combinar', {
          destino_id: grupo.plan.destino.id,
          origen_ids: grupo.plan.origenes.map((item) => item.id),
          token: grupo.plan.token,
          agente_equipo_id: grupo.agenteId,
        });
        hechos.push(grupo.nombre);
      } catch (e) {
        fallidos.push(`${grupo.nombre}: ${e.message}`);
      }
    }
    setResultado(`${hechos.length} grupo(s) combinado(s).${fallidos.length ? ` ${fallidos.length} grupo(s) no se pudieron combinar: ${fallidos.join(' ')}` : ''}`);
    setPlanes(null); setConfirmado(false); setOcupado(false);
    if (hechos.length) await onFinished?.();
  };

  return <section className="equipo-combinar duplicados-lote" aria-labelledby="titulo-combinar-duplicados">
    <h3 id="titulo-combinar-duplicados">Detectar y combinar duplicados</h3>
    <p>Busca nombres idénticos. Solo prepara grupos con clientes compatibles y un destino claro; el resto queda apartado para revisión manual.</p>
    <button type="button" className="btn sec" disabled={ocupado || (!listos.length && !excluidos.length)} onClick={preparar}>
      {ocupado ? 'Preparando…' : `Preparar vista previa (${listos.length} grupo(s) candidatos)`}
    </button>
    {!listos.length && !excluidos.length && <small>No hay grupos con coincidencias exactas de nombre.</small>}
    {planes && <div className="combinar-preview">
      <h4>Vista previa: {planes.preparados.length} grupo(s) listos</h4>
      <ul>{planes.preparados.map((grupo) => <li key={grupo.nombre}>
        <strong>{grupo.nombre}</strong>: conservará {grupo.destino.nombre} ({grupo.destino.cliente || 'Sin asignar'} · {grupo.destino.software}); combinará {grupo.origenes.map((item) => `${item.nombre} · ${item.software}`).join(', ')}.
        {grupo.agenteId && ` Se conservará el agente de la ficha ${grupo.agenteId}.`}
      </li>)}</ul>
      {!!planes.omitidos.length && <details>
        <summary>{planes.omitidos.length} grupo(s) apartados para revisión manual</summary>
        <ul>{planes.omitidos.map((grupo, index) => <li key={`${grupo.nombre}-${index}`}><strong>{grupo.nombre}</strong>: {grupo.razon}</li>)}</ul>
      </details>}
      {planes.preparados.length > 0 && <>
        <p>Las fichas de origen desaparecerán del listado. La combinación traslada las copias y referencias al destino y no se puede deshacer desde el panel.</p>
        <label className="combinar-confirmacion"><input type="checkbox" checked={confirmado} disabled={ocupado} onChange={(e) => setConfirmado(e.target.checked)} />He revisado los grupos y confirmo que cada grupo representa el mismo equipo físico.</label>
        <button type="button" className="btn" disabled={ocupado || !confirmado} onClick={combinar}>{ocupado ? 'Combinando…' : 'Confirmar combinación por lotes'}</button>
      </>}
    </div>}
    {error && <p className="errbox" role="alert">{error}</p>}
    {resultado && <p role="status">{resultado}</p>}
  </section>;
}
