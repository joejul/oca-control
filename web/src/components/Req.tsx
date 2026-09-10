/** Asterisco de campo obligatorio (Brick Ember). Uso: <label>Fecha <Req /></label> */
export default function Req() {
  return (
    <span className="req" aria-hidden="true" title="Obligatorio">
      *
    </span>
  );
}
