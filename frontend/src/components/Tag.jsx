/**
 * Etiqueta visual del estado de una copia.
 */
import { ef, LBL } from '../logic';

export default function Tag({ c }) {
  const k = ef(c);
  return <span className={'tag ' + k}>{LBL[k]}</span>;
}
