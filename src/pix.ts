/** Monta um campo do padrão EMV: id (2 dígitos) + tamanho (2 dígitos) + valor. */
function campo(id: string, valor: string): string {
  const tamanho = String(Buffer.byteLength(valor, "utf8")).padStart(2, "0");
  return id + tamanho + valor;
}

/** Tira acentos e símbolos e deixa em maiúsculas (o padrão do Pix só aceita texto simples). */
function removerAcentos(texto: string): string {
  return texto
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^A-Za-z0-9 ]/g, "")
    .toUpperCase();
}

/** Código de conferência (CRC16) que fecha o payload. */
function crc16(payload: string): string {
  let resultado = 0xffff;
  for (const byte of Buffer.from(payload, "utf8")) {
    resultado ^= byte << 8;
    for (let j = 0; j < 8; j++) {
      resultado =
        (resultado & 0x8000) !== 0
          ? ((resultado << 1) ^ 0x1021) & 0xffff
          : (resultado << 1) & 0xffff;
    }
  }
  return resultado.toString(16).toUpperCase().padStart(4, "0");
}

export interface DadosPix {
  chave: string;
  nomeRecebedor: string;
  cidade: string;
}

/**
 * Gera o texto "copia e cola" do Pix estático (padrão EMV do Banco Central).
 * Esse texto, virando QR code, preenche chave, valor e recebedor no app do banco.
 * `valor` em reais (ex.: 149.9). `identificador` é o código curto do pedido (até 25 letras/números).
 * (Era o GeradorPix.php.)
 */
export function gerarPayloadPix(pix: DadosPix, valor: number, identificador: string): string {
  const nome = removerAcentos(pix.nomeRecebedor).slice(0, 25);
  const cidade = removerAcentos(pix.cidade).slice(0, 15);
  const id = removerAcentos(identificador).slice(0, 25);

  const contaDoRecebedor = campo("00", "br.gov.bcb.pix") + campo("01", pix.chave);

  const semCrc =
    campo("00", "01") + // versão do formato
    campo("26", contaDoRecebedor) + // chave Pix
    campo("52", "0000") + // categoria do comerciante
    campo("53", "986") + // moeda: BRL
    campo("54", valor.toFixed(2)) + // valor
    campo("58", "BR") + // país
    campo("59", nome) + // nome do recebedor
    campo("60", cidade) + // cidade
    campo("62", campo("05", id)) + // identificador da transação
    "6304"; // início do CRC, valor calculado a seguir

  return semCrc + crc16(semCrc);
}