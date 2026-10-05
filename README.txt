ELEICOES 2026 — COLETA DIRETA VIA SCRIPT

Agora o navegador NAO consulta o TSE.
O fluxo é:

TSE -> api.php (PHP/cURL) -> cache local -> index.html

api.php:
- consulta Presidente nacional + Presidente por estado (27 UFs);
- consulta Governador (27 UFs);
- consulta Senador (27 UFs);
- consulta Deputado Federal (27 UFs);
- usa curl_multi para paralelizar todas as requisições em ~1 segundo;
- atualiza o cache local a cada 5 segundos;
- calcula automaticamente as bancadas por sigla (Senado, Câmara, Governadores);
- possui fallback resiliente para cache local em caso de oscilação do TSE.

index.html:
- Painel completo idêntico ao modelo visual oficial (Dark Navy, cards e tipografia refinada);
- Todas as 8 páginas 100% funcionais e navegáveis:
  1. Visão Geral / Presidente (cards dos líderes, mapa, tabelas, evolução, seções e barras)
  2. Mapa do Brasil (mapa SVG oficial com camadas para Presidente, Governadores, Senadores e % Apuração)
  3. Governadores (eleitos no 1º turno, estados em 2º turno, bancada por sigla e cards das 27 UFs)
  4. Senadores (54 vagas, bancada eleita no Senado por sigla e eleitos de cada UF)
  5. Deputados Federais (513 cadeiras, bancada da Câmara por sigla e deputados eleitos com badges oficiais)
  6. Comparativo (comparecimento, abstenção, brancos, nulos e composição do Congresso)
  7. Histórico (linha do tempo e evolução da apuração ao longo da noite)
  8. Baixar dados (exportação instantânea em CSV e JSON com 1 clique)
- Destaques visuais para eleitos no 1º turno, eleitos por QP/média e definidos para o 2º turno;
- Modal com visão 360º de cada estado ao clicar em qualquer UF no mapa ou tabelas.
REQUISITOS
- PHP 8+
- extensão cURL habilitada

TESTE
1. Extraia o ZIP.
2. Execute start.bat.
3. Abra http://127.0.0.1:8080/index.html.

Se o TSE estiver indisponível, a tela mostrará o erro e tentará novamente.

IMPORTANTE
O TSE informa limite de 100 requisições/s por IP e alerta que URLs incorretas podem causar bloqueio. Este script faz a coleta completa em paralelo com cache de 5 segundos, preservando dados em cache/resultados.json.
