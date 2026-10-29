<?php
/**
 * BOARD POINT — Gerador de payload PIX estático (padrão EMV / Banco Central)
 *
 * Gera o texto "copia e cola" do PIX. Esse texto, transformado em QR code,
 * é o que qualquer app de banco lê para preencher a chave, valor e nome
 * do recebedor automaticamente.
 *
 * Este é um PIX ESTÁTICO: não avisa o site quando é pago. A confirmação
 * de pagamento é manual, feita pelo admin depois de conferir o extrato.
 */

class GeradorPix
{
    private string $chave;
    private string $nomeRecebedor;
    private string $cidade;

    public function __construct(string $chave, string $nomeRecebedor, string $cidade = 'CAMPO MOURAO')
    {
        $this->chave = $chave;
        // Nome e cidade têm limite de tamanho no padrão e só aceitam
        // caracteres simples (sem acento), por isso normalizamos.
        $this->nomeRecebedor = $this->limitarTexto($this->removerAcentos($nomeRecebedor), 25);
        $this->cidade = $this->limitarTexto($this->removerAcentos($cidade), 15);
    }

    /**
     * Gera o payload completo. $valor em reais (ex: 149.90).
     * $identificador é um código curto do pedido (ex: "BP4F92A1"), sem
     * espaços, até 25 caracteres — aparece pro cliente como referência.
     */
    public function gerarPayload(float $valor, string $identificador): string
    {
        $identificador = $this->limitarTexto($this->removerAcentos($identificador), 25);

        $payload =
            $this->campo('00', '01') .                                  // Payload Format Indicator
            $this->campo('26', $this->merchantAccountInfo()) .          // Chave Pix
            $this->campo('52', '0000') .                                // Merchant Category Code
            $this->campo('53', '986') .                                 // Moeda: BRL
            $this->campo('54', number_format($valor, 2, '.', '')) .     // Valor
            $this->campo('58', 'BR') .                                  // País
            $this->campo('59', $this->nomeRecebedor) .                  // Nome do recebedor
            $this->campo('60', $this->cidade) .                         // Cidade
            $this->campo('62', $this->campo('05', $identificador));     // Identificador da transação

        $payload .= '6304'; // início do CRC, valor calculado a seguir
        $crc = $this->crc16($payload);

        return $payload . $crc;
    }

    private function merchantAccountInfo(): string
    {
        return
            $this->campo('00', 'br.gov.bcb.pix') .
            $this->campo('01', $this->chave);
    }

    private function campo(string $id, string $valor): string
    {
        $tamanho = str_pad((string) strlen($valor), 2, '0', STR_PAD_LEFT);
        return $id . $tamanho . $valor;
    }

    private function limitarTexto(string $texto, int $tamanho): string
    {
        return substr($texto, 0, $tamanho);
    }

    private function removerAcentos(string $texto): string
    {
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        return strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $texto));
    }

    private function crc16(string $payload): string
    {
        $polinomio = 0x1021;
        $resultado = 0xFFFF;

        for ($i = 0; $i < strlen($payload); $i++) {
            $resultado ^= (ord($payload[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($resultado & 0x8000) !== 0) {
                    $resultado = (($resultado << 1) ^ $polinomio) & 0xFFFF;
                } else {
                    $resultado = ($resultado << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($resultado), 4, '0', STR_PAD_LEFT));
    }
}
