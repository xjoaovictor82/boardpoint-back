/** Valida CPF (com os dígitos verificadores). Aceita com ou sem pontuação. */
export function validarCPF(valor: string): boolean {
  const cpf = valor.replace(/\D/g, "");

  // 11 dígitos e não pode ser tudo igual (ex.: 111.111.111-11)
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
    return false;
  }

  for (let t = 9; t < 11; t++) {
    let soma = 0;
    for (let c = 0; c < t; c++) {
      soma += Number(cpf[c]) * (t + 1 - c);
    }
    const digito = ((10 * soma) % 11) % 10;
    if (Number(cpf[t]) !== digito) {
      return false;
    }
  }

  return true;
}

export function validarEmail(email: string): boolean {
  return email.length <= 150 && /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
}

/** Mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo. */
export function validarSenha(senha: string): boolean {
  if (senha.length < 8) return false;
  if (!/[A-Z]/.test(senha)) return false;
  if (!/[a-z]/.test(senha)) return false;
  if (!/[0-9]/.test(senha)) return false;
  if (!/[\W_]/.test(senha)) return false;
  return true;
}