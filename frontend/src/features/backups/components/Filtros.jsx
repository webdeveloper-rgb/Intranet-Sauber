/** Configura los filtros de copias usando la barra compartida del panel. */
import { FILTROS, EXTRA, ALERTA, PROGRAMAS } from '../../../shared/lib/logic';
import BarraFiltros from '../../../shared/ui/BarraFiltros';

export default function Filtros({ filtro, setFiltro, n, f, setF, resultados, total, softwares = [] }) {
  const { cli, tipo, software, desde, hasta, orden, horas } = f;
  const filtroEstado = FILTROS.find(([clave]) => clave === filtro)?.[1] || filtro;
  const filtrosActivos = [
    ...(filtro !== 'todos' ? [{ clave: 'estado', etiqueta: `Estado: ${filtroEstado}` }] : []),
    ...(cli ? [{ clave: 'cli', etiqueta: `Búsqueda: ${cli}` }] : []),
    ...(tipo ? [{ clave: 'tipo', etiqueta: `Tipo: ${tipo.startsWith('programa:') ? tipo.slice(9) : tipo}` }] : []),
    ...(software ? [{ clave: 'software', etiqueta: `Software: ${software}` }] : []),
    ...(desde ? [{ clave: 'desde', etiqueta: `Desde: ${desde}` }] : []),
    ...(hasta ? [{ clave: 'hasta', etiqueta: `Hasta: ${hasta}` }] : []),
  ];
  const quitarFiltro = (clave) => {
    if (clave === 'estado') setFiltro('todos');
    else setF({ [clave]: '' });
  };
  const limpiarFiltros = () => {
    setFiltro('todos');
    setF({ cli: '', tipo: '', software: '', desde: '', hasta: '' });
  };
  const filtroAlerta = ALERTA[filtro] && n[EXTRA[filtro]] > 0;
  const alertas = (n.sc || 0) + (n.a || 0);

  return <BarraFiltros className="fil" title="Filtrar copias"
    search={{ id: 'filtro-buscar-copias', label: 'Buscar', value: cli,
      placeholder: 'Cliente, equipo, software o error…', onChange: (event) => setF({ cli: event.target.value }) }}
    fields={[
      { id: 'filtro-copias', className: 'fil-estado', label: 'Estado', type: 'select', value: filtro,
        controlClassName: filtroAlerta ? 'al' : '',
        onChange: (event) => setFiltro(event.target.value),
        options: FILTROS.map(([key, label]) => [key, `${label}${EXTRA[key] ? ` (${n[EXTRA[key]]})` : ''}`]),
        ariaLabel: 'Filtrar copias por estado' },
      { id: 'filtro-tipo-equipo', label: 'Tipo de equipo', type: 'select', value: tipo,
        onChange: (event) => setF({ tipo: event.target.value }),
        options: [['', 'Todos'], ['usuario', 'Usuarios'], ['servidor', 'Servidores'], ['programa', 'Programas (todos)'],
          ...PROGRAMAS.map((programa) => [`programa:${programa}`, programa])] },
      { id: 'filtro-software-copia', label: 'Software de copia', type: 'select', value: software,
        onChange: (event) => setF({ software: event.target.value }),
        options: [['', 'Todos'], ...softwares.map((nombre) => [nombre, nombre])] },
      { id: 'filtro-fecha-desde', label: 'Fecha desde', type: 'date', value: desde, max: hasta || undefined,
        onChange: (event) => setF({ desde: event.target.value }) },
      { id: 'filtro-fecha-hasta', label: 'Fecha hasta', type: 'date', value: hasta, min: desde || undefined,
        onChange: (event) => setF({ hasta: event.target.value }) },
      { id: 'filtro-orden-copias', label: 'Ordenar', type: 'select', value: orden,
        onChange: (event) => setF({ orden: event.target.value }),
        options: [['fecha-desc', 'Fecha: recientes primero'], ['fecha-asc', 'Fecha: antiguas primero'],
          ['cliente-az', 'Cliente: A a Z'], ['cliente-za', 'Cliente: Z a A'],
          ['equipo-az', 'Equipo: A a Z'], ['equipo-za', 'Equipo: Z a A']] },
      { id: 'filtro-avisar-horas', label: 'Avisar errores tras', type: 'select', value: String(horas),
        onChange: (event) => setF({ horas: Number(event.target.value) }),
        options: [12, 24, 48, 72].map((horas) => [String(horas), `${horas} h`]) },
    ]}
    summary={<span className={`aviso-cuenta${alertas ? ' activa' : ''}`}
      title={`Avisos: ${n.sc || 0} errores críticos sin copia y ${n.a || 0} errores atrasados`}>
      Avisos <strong>{alertas}</strong>
    </span>}
    count={`${resultados}${Number.isFinite(total) ? ` de ${total}` : ''} resultado${resultados === 1 ? '' : 's'}`}
    activeFilters={filtrosActivos} onRemoveFilter={quitarFiltro} onClear={limpiarFiltros} />;
}
