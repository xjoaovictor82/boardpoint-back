-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Tempo de geração: 24/08/2026 às 03:15
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `boardpoint`
--

DELIMITER $$
--
-- Procedimentos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_contar_produtos` (IN `p_busca` VARCHAR(255), IN `p_categoria_slug` VARCHAR(60))  READS SQL DATA BEGIN
  DECLARE v_busca_normalizada VARCHAR(255);
  SET v_busca_normalizada = fn_remover_acentos(TRIM(p_busca)) COLLATE utf8mb4_unicode_ci;

  SELECT COUNT(*) AS total
  FROM vw_produtos_completo
  WHERE
    (p_categoria_slug COLLATE utf8mb4_unicode_ci = 'todos' OR categoria_slug = p_categoria_slug COLLATE utf8mb4_unicode_ci)
    AND (
      v_busca_normalizada = ''
      OR fn_remover_acentos(nome) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', v_busca_normalizada, '%')
      OR fn_remover_acentos(categoria_nome) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', v_busca_normalizada, '%')
    );
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_estatisticas_dashboard` ()   BEGIN
  -- CTE única sobre "produtos": em vez de reler a tabela em 5 subqueries
  -- separadas (uma pra cada métrica), agrega tudo numa única passada e
  -- reaproveita o resultado (agregado_produtos) nas colunas finais.
  WITH agregado_produtos AS (
    SELECT
      COUNT(*)                                    AS total_produtos,
      COUNT(CASE WHEN preco IS NOT NULL THEN 1 END) AS produtos_com_preco,
      COUNT(CASE WHEN estoque = 0 THEN 1 END)      AS produtos_sem_estoque,
      COALESCE(SUM(estoque), 0)                    AS estoque_total,
      COALESCE(AVG(preco), 0)                      AS preco_medio
    FROM produtos
  ),
  agregado_categorias AS (
    SELECT COUNT(*) AS total_categorias
    FROM categorias
  )
  SELECT
    ap.total_produtos,
    ac.total_categorias,
    ap.produtos_com_preco,
    ap.produtos_sem_estoque,
    ap.estoque_total,
    ap.preco_medio
  FROM agregado_produtos ap
  CROSS JOIN agregado_categorias ac;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_listar_produtos` (IN `p_busca` VARCHAR(255), IN `p_categoria_slug` VARCHAR(60), IN `p_limite` INT, IN `p_offset` INT)  READS SQL DATA BEGIN
  DECLARE v_busca_normalizada VARCHAR(255);
  SET v_busca_normalizada = fn_remover_acentos(TRIM(p_busca)) COLLATE utf8mb4_unicode_ci;

  SELECT
    id, nome, preco, estoque, imagem, ativo,
    categoria_id, categoria_slug, categoria_nome
  FROM vw_produtos_completo
  WHERE
    (p_categoria_slug COLLATE utf8mb4_unicode_ci = 'todos' OR categoria_slug = p_categoria_slug COLLATE utf8mb4_unicode_ci)
    AND (
      v_busca_normalizada = ''
      OR fn_remover_acentos(nome) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', v_busca_normalizada, '%')
      OR fn_remover_acentos(categoria_nome) COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', v_busca_normalizada, '%')
    )
  ORDER BY categoria_nome, nome
  LIMIT p_limite OFFSET p_offset;
END$$

--
-- Funções
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_remover_acentos` (`texto` VARCHAR(255)) RETURNS VARCHAR(255) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci DETERMINISTIC READS SQL DATA BEGIN
  SET texto = LOWER(texto);
  SET texto = REPLACE(texto, 'á', 'a');
  SET texto = REPLACE(texto, 'à', 'a');
  SET texto = REPLACE(texto, 'ã', 'a');
  SET texto = REPLACE(texto, 'â', 'a');
  SET texto = REPLACE(texto, 'ä', 'a');
  SET texto = REPLACE(texto, 'é', 'e');
  SET texto = REPLACE(texto, 'è', 'e');
  SET texto = REPLACE(texto, 'ê', 'e');
  SET texto = REPLACE(texto, 'ë', 'e');
  SET texto = REPLACE(texto, 'í', 'i');
  SET texto = REPLACE(texto, 'ì', 'i');
  SET texto = REPLACE(texto, 'î', 'i');
  SET texto = REPLACE(texto, 'ï', 'i');
  SET texto = REPLACE(texto, 'ó', 'o');
  SET texto = REPLACE(texto, 'ò', 'o');
  SET texto = REPLACE(texto, 'õ', 'o');
  SET texto = REPLACE(texto, 'ô', 'o');
  SET texto = REPLACE(texto, 'ö', 'o');
  SET texto = REPLACE(texto, 'ú', 'u');
  SET texto = REPLACE(texto, 'ù', 'u');
  SET texto = REPLACE(texto, 'û', 'u');
  SET texto = REPLACE(texto, 'ü', 'u');
  SET texto = REPLACE(texto, 'ç', 'c');
  SET texto = REPLACE(texto, 'ñ', 'n');
  RETURN texto;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estrutura para tabela `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `usuario` varchar(60) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `admins`
--

INSERT INTO `admins` (`id`, `usuario`, `senha_hash`, `criado_em`) VALUES
(1, 'admin', '$2y$10$WHcfvBQD8fBR/1u1By4Z5O4ybkqny4/PTfmRXg1W0NPL39jFz6yte', '2026-07-30 22:01:07');

-- --------------------------------------------------------

--
-- Estrutura para tabela `categorias`
--

CREATE TABLE `categorias` (
  `id` int(11) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `categorias`
--

INSERT INTO `categorias` (`id`, `slug`, `nome`, `criado_em`, `atualizado_em`) VALUES
(1, 'tenisnike', 'Tênis Nike', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(3, 'tenisous', 'Tênis OUS', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(4, 'chinelos', 'Chinelos', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(6, 'camisasbaw', 'Camisas BAW', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(7, 'moletomnike', 'Moletom Nike', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(8, 'relogiocasio', 'Relógio Casio', '2026-07-30 22:01:08', '2026-07-30 22:01:08'),
(9, 'grizzly', 'Grizzly', '2026-07-30 22:01:08', '2026-07-30 22:01:08');

-- --------------------------------------------------------

--
-- Estrutura para tabela `contatos`
--

CREATE TABLE `contatos` (
  `id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `mensagem` text NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `contatos`
--

INSERT INTO `contatos` (`id`, `nome`, `email`, `mensagem`, `lida`, `criado_em`) VALUES
(1, 'gustavo', 'gustavo.pinguelo@grupointegrado.br', 'ola, gostei muito do site', 0, '2026-08-05 20:59:21'),
(2, 'guhpkrl', 'gupereirapinguelo@gmail.com', 'site ta bonito igual o dono', 0, '2026-08-23 22:03:14');

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedidos`
--

CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `cliente_nome` varchar(150) NOT NULL,
  `cliente_telefone` varchar(30) NOT NULL,
  `cliente_email` varchar(150) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL,
  `status` enum('aguardando_pagamento','pago','cancelado') NOT NULL DEFAULT 'aguardando_pagamento',
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `pedido_itens`
--

CREATE TABLE `pedido_itens` (
  `id` int(11) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `produto_nome` varchar(150) NOT NULL,
  `preco_unitario` decimal(10,2) NOT NULL,
  `quantidade` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` int(11) NOT NULL,
  `categoria_id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `preco` decimal(10,2) DEFAULT NULL,
  `estoque` int(11) NOT NULL DEFAULT 0,
  `imagem` varchar(255) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `produtos`
--

INSERT INTO `produtos` (`id`, `categoria_id`, `nome`, `preco`, `estoque`, `imagem`, `ativo`, `criado_em`, `atualizado_em`) VALUES
(1, 1, 'Tênis Nike SB — Azul', 399.90, 10, 'produtos/tenisnike/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-10 20:17:22'),
(2, 1, 'Tênis Nike SB — Rosa', 429.90, 10, 'produtos/tenisnike/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-10 20:18:09'),
(4, 3, 'Tênis OUS — ÖUS 2K Preto / Saara Gold', 279.90, 10, 'produtos/tenisous/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:55:59'),
(5, 3, 'Tênis OUS — ÖUS 2K Preto / Azul', 289.90, 10, 'produtos/tenisous/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:56:16'),
(6, 3, 'Tênis OUS — ÖUS 2K Off-White / Bordô ( Uva)', 299.90, 10, 'produtos/tenisous/3.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:57:02'),
(7, 3, 'Tênis OUS — ÖUS 2K All Black /  Purple', 309.90, 10, 'produtos/tenisous/4.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:57:59'),
(8, 3, 'Tênis OUS — ÖUS 2K Bege / Oliva / Café', 279.90, 10, 'produtos/tenisous/5.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:58:14'),
(9, 3, 'Tênis OUS — ÖUS 2K Cinza / Grey', 289.90, 10, 'produtos/tenisous/6.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:58:27'),
(10, 4, 'Chinelo — John Roger Slide Nuvem', 79.90, 10, 'produtos/chinelos/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:10:34'),
(11, 4, 'Chinelo — John Roger Slide Texturizado / Ondulado verde', 89.90, 10, 'produtos/chinelos/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:11:37'),
(12, 4, 'Chinelo — John Roger Slide Nuvem preto', 79.90, 10, 'produtos/chinelos/3.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:12:44'),
(13, 4, 'Chinelo — John Roger Slide Robusto creme', 99.90, 10, 'produtos/chinelos/4.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:08:22'),
(17, 6, 'Camiseta BAW Dálmata', 99.90, 10, 'produtos/camisasbaw/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 20:16:59'),
(18, 6, 'Camiseta BAW x Scooby-Doo Momia / Mummy (Off-White)', 109.90, 10, 'produtos/camisasbaw/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 20:17:03'),
(19, 6, 'Camiseta BAW x Scooby-Doo Typo / Letters (Off-White)', 99.90, 10, 'produtos/camisasbaw/3.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 20:17:10'),
(20, 6, ' Camiseta BAW x Scooby-Doo Silhueta / Running Gang (Off-White)', 119.90, 10, 'produtos/camisasbaw/4.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 20:17:15'),
(21, 6, 'Camiseta BAW x Scooby-Doo Surf\'s Up', 109.90, 10, 'produtos/camisasbaw/5.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 20:16:29'),
(22, 7, 'Moletom Nike — azul', 199.90, 10, 'produtos/moletomnike/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-05 21:01:55'),
(23, 7, 'Moletom Nike — marrom', 219.90, 10, 'produtos/moletomnike/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-05 21:02:17'),
(24, 7, 'Moletom Nike — creme', 199.90, 10, 'produtos/moletomnike/3.jpg', 1, '2026-07-30 22:01:08', '2026-08-17 21:00:43'),
(25, 7, 'Moletom Nike — preto', 229.90, 10, 'produtos/moletomnike/4.jpg', 1, '2026-07-30 22:01:08', '2026-08-05 21:02:48'),
(26, 8, 'Relógio Casio —  LA670WEGA-1 (Dourado Vintage)', 249.90, 10, 'produtos/relogiocasio/1.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:43:04'),
(27, 8, 'Relógio Casio — G-Shock GA-100-1A1 (Blackout / All Black)', 259.90, 10, 'produtos/relogiocasio/2.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:43:47'),
(28, 8, 'Relógio Casio — G-Shock GMA-S2100-4A (CasiOak / Rose-Beige)', 279.90, 10, 'produtos/relogiocasio/3.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:44:21'),
(29, 8, 'Relógio Casio — Casio LTP-V006D-1B (Analógico Clássico)', 249.90, 10, 'produtos/relogiocasio/4.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:44:49'),
(30, 8, 'Relógio Casio — G-Shock DW-6900-1V', 269.90, 10, 'produtos/relogiocasio/5.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:45:21'),
(31, 8, 'Relógio Casio — Casio A158WEA-1 / A168W', 259.90, 10, 'produtos/relogiocasio/6.jpg', 1, '2026-07-30 22:01:08', '2026-08-11 20:45:39'),
(34, 1, 'Nike Air Max Plus — Azul', 599.90, 10, 'produtos/tenisnike/3.jpg', 1, '2026-08-04 20:51:47', '2026-08-04 20:51:47'),
(35, 1, 'Nike Air Max Plus — Preto', 599.90, 10, 'produtos/tenisnike/4.jpg', 1, '2026-08-04 20:51:47', '2026-08-04 20:51:47'),
(36, 1, 'Nike Air Max Plus — Vermelho', 599.90, 10, 'produtos/tenisnike/5.jpg', 1, '2026-08-04 20:51:47', '2026-08-04 20:51:47'),
(37, 9, 'Camisa Grizzly — Amarela', 129.90, 10, 'produtos/grizzly/1.jpg', 1, '2026-08-04 22:24:19', '2026-08-05 21:05:54'),
(38, 9, 'Camisa Grizzly — Azul', 129.90, 10, 'produtos/grizzly/2.jpg', 1, '2026-08-04 22:24:19', '2026-08-05 21:06:05'),
(39, 9, 'Camisa Grizzly — Verde', 129.90, 10, 'produtos/grizzly/3.jpg', 1, '2026-08-04 22:24:19', '2026-08-05 21:06:13'),
(40, 9, 'Camisa Grizzly — Vermelha', 129.90, 10, 'produtos/grizzly/4.jpg', 1, '2026-08-04 22:24:19', '2026-08-05 21:06:19'),
(41, 7, 'Moletom Nike — Vermelho', 219.90, 10, 'produtos/moletomnike/5.jpg', 1, '2026-08-05 21:34:20', '2026-08-05 21:34:20'),
(42, 9, 'Camisa Grizzly — Roxa', 129.90, 10, 'produtos/grizzly/5.jpg', 1, '2026-08-05 21:34:20', '2026-08-11 22:22:20'),
(43, 4, 'Chinelo — John Roger Slide Robusto creme azul', 89.90, 10, 'produtos/chinelos/5.jpg', 1, '2026-08-05 21:34:20', '2026-08-11 20:13:48');

--
-- Acionadores `produtos`
--
DELIMITER $$
CREATE TRIGGER `trg_produtos_before_insert` BEFORE INSERT ON `produtos` FOR EACH ROW BEGIN
  IF NEW.preco IS NOT NULL AND NEW.preco < 0 THEN
    SET NEW.preco = ABS(NEW.preco);
  END IF;
  IF NEW.estoque < 0 THEN
    SET NEW.estoque = ABS(NEW.estoque);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_produtos_before_update` BEFORE UPDATE ON `produtos` FOR EACH ROW BEGIN
  IF NEW.preco IS NOT NULL AND NEW.preco < 0 THEN
    SET NEW.preco = ABS(NEW.preco);
  END IF;
  IF NEW.estoque < 0 THEN
    SET NEW.estoque = ABS(NEW.estoque);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estrutura stand-in para view `vw_dashboard_categorias`
-- (Veja abaixo para a visão atual)
--
CREATE TABLE `vw_dashboard_categorias` (
`id` int(11)
,`slug` varchar(60)
,`nome` varchar(100)
,`total_produtos` bigint(21)
);

-- --------------------------------------------------------

--
-- Estrutura stand-in para view `vw_produtos_completo`
-- (Veja abaixo para a visão atual)
--
CREATE TABLE `vw_produtos_completo` (
`id` int(11)
,`nome` varchar(150)
,`preco` decimal(10,2)
,`estoque` int(11)
,`imagem` varchar(255)
,`ativo` tinyint(1)
,`categoria_id` int(11)
,`categoria_slug` varchar(60)
,`categoria_nome` varchar(100)
);

-- --------------------------------------------------------

--
-- Estrutura para view `vw_dashboard_categorias`
--
DROP TABLE IF EXISTS `vw_dashboard_categorias`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_dashboard_categorias`  AS SELECT `c`.`id` AS `id`, `c`.`slug` AS `slug`, `c`.`nome` AS `nome`, count(`p`.`id`) AS `total_produtos` FROM (`categorias` `c` left join `produtos` `p` on(`p`.`categoria_id` = `c`.`id`)) GROUP BY `c`.`id`, `c`.`slug`, `c`.`nome` ORDER BY `c`.`nome` ASC ;

-- --------------------------------------------------------

--
-- Estrutura para view `vw_produtos_completo`
--
DROP TABLE IF EXISTS `vw_produtos_completo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_produtos_completo`  AS SELECT `p`.`id` AS `id`, `p`.`nome` AS `nome`, `p`.`preco` AS `preco`, `p`.`estoque` AS `estoque`, `p`.`imagem` AS `imagem`, `p`.`ativo` AS `ativo`, `c`.`id` AS `categoria_id`, `c`.`slug` AS `categoria_slug`, `c`.`nome` AS `categoria_nome` FROM (`produtos` `p` join `categorias` `c` on(`c`.`id` = `p`.`categoria_id`)) ;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario` (`usuario`);

--
-- Índices de tabela `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Índices de tabela `contatos`
--
ALTER TABLE `contatos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contatos_criado_em` (`criado_em`);

--
-- Índices de tabela `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`),
  ADD KEY `idx_pedidos_status` (`status`),
  ADD KEY `idx_pedidos_criado_em` (`criado_em`);

--
-- Índices de tabela `pedido_itens`
--
ALTER TABLE `pedido_itens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_itens_produto` (`produto_id`),
  ADD KEY `idx_itens_pedido` (`pedido_id`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_produtos_categoria` (`categoria_id`),
  ADD KEY `idx_produtos_nome` (`nome`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de tabela `contatos`
--
ALTER TABLE `contatos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `pedido_itens`
--
ALTER TABLE `pedido_itens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `pedido_itens`
--
ALTER TABLE `pedido_itens`
  ADD CONSTRAINT `fk_itens_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_itens_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`);

--
-- Restrições para tabelas `produtos`
--
ALTER TABLE `produtos`
  ADD CONSTRAINT `fk_produtos_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
