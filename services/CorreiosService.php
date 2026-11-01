<?php
/**
 * SHINKA — integração com APIs dos Correios para preço, prazo e rastreio.
 * Sem token, preço e prazo usam uma estimativa local dinâmica; nenhum preço
 * fixo por modalidade é usado no checkout.
 */
class CorreiosService
{
    private array $config;

    public function __construct()
    {
        $local=__DIR__.'/../config/correios.php';
        $config=is_file($local)?require $local:[];
        $this->config=[
            'token'=>trim((string)(getenv('CORREIOS_TOKEN')?:($config['token']??''))),
            'origem_cep'=>preg_replace('/\D/','',(string)(getenv('CORREIOS_ORIGEM_CEP')?:($config['origem_cep']??'30140071'))),
            'pac_codigo'=>trim((string)(getenv('CORREIOS_PAC_CODIGO')?:($config['pac_codigo']??'03298'))),
            'sedex_codigo'=>trim((string)(getenv('CORREIOS_SEDEX_CODIGO')?:($config['sedex_codigo']??'03220'))),
            'ambiente'=>trim((string)(getenv('CORREIOS_AMBIENTE')?:($config['ambiente']??'producao'))),
            'nu_dr'=>trim((string)(getenv('CORREIOS_NU_DR')?:($config['nu_dr']??''))),
            'nu_contrato'=>trim((string)(getenv('CORREIOS_NU_CONTRATO')?:($config['nu_contrato']??''))),
            'peso_kg_padrao'=>(float)(getenv('CORREIOS_PESO_KG_PADRAO')?:($config['peso_kg_padrao']??1.0)),
            'altura_cm_padrao'=>(float)(getenv('CORREIOS_ALTURA_CM_PADRAO')?:($config['altura_cm_padrao']??10.0)),
            'largura_cm_padrao'=>(float)(getenv('CORREIOS_LARGURA_CM_PADRAO')?:($config['largura_cm_padrao']??20.0)),
            'comprimento_cm_padrao'=>(float)(getenv('CORREIOS_COMPRIMENTO_CM_PADRAO')?:($config['comprimento_cm_padrao']??30.0)),
        ];
        if(!in_array($this->config['ambiente'],['producao','homologacao'],true)) $this->config['ambiente']='producao';
    }

    public function configurada(): bool { return $this->config['token']!==''; }

    public function servicos(): array
    {
        return [
            ['codigo'=>$this->config['pac_codigo'],'nome'=>'PAC','dias_fallback'=>8],
            ['codigo'=>$this->config['sedex_codigo'],'nome'=>'SEDEX','dias_fallback'=>3],
        ];
    }

    public function calcularPrazo(string $cepDestino,string $servico): array
    {
        $cepDestino=preg_replace('/\D/','',$cepDestino);
        if(strlen($cepDestino)!==8) throw new InvalidArgumentException('Informe um CEP de destino válido.');
        $service=$this->serviceByCode($servico);
        if($this->configurada()){
            $url=$this->base().'/prazo/v3/v1/nacional/'.rawurlencode($service['codigo']).'?'.http_build_query([
                'cepOrigem'=>$this->config['origem_cep'],'cepDestino'=>$cepDestino,
            ]);
            $resultado=$this->request('GET',$url);
            if($resultado['ok']){
                $payload=$resultado['data'];
                $dias=(int)($payload['prazoEntrega']??0);
                return [
                    'configurada'=>true,'codigo'=>$service['codigo'],'servico'=>$service['nome'],'dias'=>$dias,
                    'data_prevista'=>!empty($payload['dataMaxima'])?substr((string)$payload['dataMaxima'],0,10):null,
                    'mensagem'=>$dias>0?"Entrega estimada em {$dias} dias.":'Prazo retornado pelos Correios.',
                ];
            }
        }
        $dias=(int)$service['dias_fallback'];
        $date=(new DateTimeImmutable('today'))->modify("+{$dias} days");
        return ['configurada'=>false,'codigo'=>$service['codigo'],'servico'=>$service['nome'],'dias'=>$dias,'data_prevista'=>$date->format('Y-m-d'),'mensagem'=>"Estimativa de referência: até {$dias} dias. Configure o token dos Correios para usar o prazo oficial."];
    }

    public function calcularPrazoTodas(string $cepDestino): array
    {
        $out=[];
        foreach($this->servicos() as $service) $out[]=$this->calcularPrazo($cepDestino,$service['codigo']);
        return $out;
    }

    public function calcularPreco(string $cepDestino,string $servico,array $pacote): array
    {
        $cepDestino=preg_replace('/\D/','',$cepDestino);
        if(strlen($cepDestino)!==8) throw new InvalidArgumentException('Informe um CEP de destino válido.');
        $service=$this->serviceByCode($servico);
        $pacote=$this->normalizarPacote($pacote);
        if($this->configurada()){
            $query=[
                'cepDestino'=>$cepDestino,
                'cepOrigem'=>$this->config['origem_cep'],
                'psObjeto'=>(string)max(1,(int)round($pacote['peso_kg']*1000)),
                'tpObjeto'=>'2',
                'comprimento'=>(string)max(1,(int)round($pacote['comprimento_cm'])),
                'largura'=>(string)max(1,(int)round($pacote['largura_cm'])),
                'altura'=>(string)max(1,(int)round($pacote['altura_cm'])),
            ];
            if($this->config['nu_dr']!=='') $query['nuDR']=$this->config['nu_dr'];
            if($this->config['nu_contrato']!=='') $query['nuContrato']=$this->config['nu_contrato'];
            $url=$this->base().'/preco/v1/nacional/'.rawurlencode($service['codigo']).'?'.http_build_query($query);
            $resultado=$this->request('GET',$url);
            if($resultado['ok']){
                $payload=$resultado['data'];
                $valor=$this->normalizarValor($payload['pcFinal']??$payload['pcReferencia']??$payload['pcBase']??null);
                if($valor!==null){
                    return ['configurada'=>true,'codigo'=>$service['codigo'],'servico'=>$service['nome'],'valor'=>round($valor,2),'peso_kg'=>$pacote['peso_kg'],'altura_cm'=>$pacote['altura_cm'],'largura_cm'=>$pacote['largura_cm'],'comprimento_cm'=>$pacote['comprimento_cm'],'mensagem'=>'Valor calculado pelos Correios.'];
                }
            }
        }
        return $this->calcularPrecoFallback($cepDestino,$service,$pacote);
    }

    public function calcularFreteTodas(string $cepDestino,array $pacote): array
    {
        $out=[];
        foreach($this->servicos() as $service){
            $preco=$this->calcularPreco($cepDestino,$service['codigo'],$pacote);
            $prazo=$this->calcularPrazo($cepDestino,$service['codigo']);
            $out[]=array_merge($preco,[
                'dias'=>$prazo['dias']??null,'data_prevista'=>$prazo['data_prevista']??null,
                'configurada_prazo'=>$prazo['configurada']??false,'mensagem_prazo'=>$prazo['mensagem']??null,
            ]);
        }
        return $out;
    }

    public function pacotePadrao(): array
    {
        return $this->normalizarPacote(['peso_kg'=>$this->config['peso_kg_padrao'],'altura_cm'=>$this->config['altura_cm_padrao'],'largura_cm'=>$this->config['largura_cm_padrao'],'comprimento_cm'=>$this->config['comprimento_cm_padrao']]);
    }

    private function normalizarPacote(array $pacote): array
    {
        return [
            'peso_kg'=>max(0.01,(float)($pacote['peso_kg']??$this->config['peso_kg_padrao'])),
            'altura_cm'=>max(1,(float)($pacote['altura_cm']??$this->config['altura_cm_padrao'])),
            'largura_cm'=>max(1,(float)($pacote['largura_cm']??$this->config['largura_cm_padrao'])),
            'comprimento_cm'=>max(1,(float)($pacote['comprimento_cm']??$this->config['comprimento_cm_padrao'])),
        ];
    }

    private function calcularPrecoFallback(string $cepDestino,array $service,array $pacote): array
    {
        $origem=(int)substr($this->config['origem_cep'],0,3);
        $destino=(int)substr($cepDestino,0,3);
        $distancia=min(1.5,abs($origem-$destino)/1000);
        $volume=$pacote['altura_cm']*$pacote['largura_cm']*$pacote['comprimento_cm'];
        $pesoCubico=$volume/6000;
        $pesoCobravel=max($pacote['peso_kg'],$pesoCubico);
        $excessoCubico=max(0.0,$pesoCubico-1.0);
        $valor=$service['nome']==='SEDEX'
            ? 22.00+($pesoCobravel*9.50)+($excessoCubico*3.00)+($distancia*5.50)
            : 14.00+($pesoCobravel*6.50)+($excessoCubico*2.00)+($distancia*4.00);
        return ['configurada'=>false,'codigo'=>$service['codigo'],'servico'=>$service['nome'],'valor'=>round(max(1.0,$valor),2),'peso_kg'=>$pacote['peso_kg'],'altura_cm'=>$pacote['altura_cm'],'largura_cm'=>$pacote['largura_cm'],'comprimento_cm'=>$pacote['comprimento_cm'],'mensagem'=>'Estimativa local baseada em peso, dimensões e faixa de CEP. Configure o token dos Correios para o valor oficial.'];
    }

    private function normalizarValor(mixed $valor): ?float
    {
        if($valor===null||$valor==='') return null;
        $texto=trim((string)$valor);
        if(str_contains($texto,',')){
            $texto=str_replace('.','',$texto);
            $texto=str_replace(',','.',$texto);
        }
        return is_numeric($texto)?(float)$texto:null;
    }

    public function rastrear(string $codigo): array
    {
        $codigo=strtoupper(preg_replace('/[^A-Z0-9]/','',$codigo));
        if(!preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/',$codigo)) throw new InvalidArgumentException('Informe um código de rastreio válido. Ex.: AA123456789BR.');
        $resultado=$this->request('GET',$this->base().'/srorastro/v1/objetos/'.rawurlencode($codigo).'?resultado=T');
        if(!$resultado['ok']) throw new RuntimeException($resultado['message']);
        $payload=$resultado['data'];
        $objeto=$payload['objetos'][0]??($payload['objeto']??$payload);
        $eventos=$objeto['eventos']??[]; if(!is_array($eventos)) $eventos=[];
        $norm=[];
        foreach($eventos as $e){ if(!is_array($e)) continue; $norm[]=['data'=>$e['dtHrCriado']??$e['dtHrEvento']??null,'status'=>trim((string)($e['descricao']??$e['descStatus']??$e['status']??'Atualização')),'detalhe'=>trim((string)($e['unidade']['endereco']['cidade']??$e['unidade']['endereco']['uf']??''))]; }
        return ['configurada'=>true,'codigo'=>$codigo,'status'=>$norm?($norm[count($norm)-1]['status']):'Objeto rastreado','eventos'=>$norm,'raw'=>$payload,'url_oficial'=>$this->oficial($codigo)];
    }

    public function oficial(string $codigo): string { $codigo=strtoupper(preg_replace('/[^A-Z0-9]/','',$codigo)); return 'https://rastreamento.correios.com.br/app/index.php?objetos='.rawurlencode($codigo); }

    private function serviceByCode(string $code): array { foreach($this->servicos() as $service) if($service['codigo']===trim($code)) return $service; throw new InvalidArgumentException('Modalidade de entrega não configurada.'); }
    private function base(): string { return $this->config['ambiente']==='homologacao'?'https://apihom.correios.com.br':'https://api.correios.com.br'; }

    private function localizarCaBundle(): ?string
    {
        $candidatos = [];
        $curlCainfo = trim((string) ini_get('curl.cainfo'));
        if ($curlCainfo !== '') $candidatos[] = $curlCainfo;
        $opensslCafile = trim((string) ini_get('openssl.cafile'));
        if ($opensslCafile !== '') $candidatos[] = $opensslCafile;
        $envBundle = trim((string) getenv('CURL_CA_BUNDLE'));
        if ($envBundle !== '') $candidatos[] = $envBundle;
        $candidatos[] = __DIR__ . '/../config/cacert.pem';
        $phpDir = dirname(PHP_BINARY);
        $candidatos[] = $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        $candidatos[] = $phpDir . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt';
        $candidatos[] = dirname($phpDir) . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt';
        foreach ($candidatos as $arquivo) {
            if ($arquivo !== '' && is_file($arquivo) && is_readable($arquivo)) return $arquivo;
        }
        return null;
    }

    private function request(string $method,string $url,?array $body=null): array
    {
        if(!function_exists('curl_init')) return ['ok'=>false,'message'=>'A extensão cURL do PHP está desabilitada.'];
        $headers=['Accept: application/json','Content-Type: application/json'];
        if($this->config['token']!=='') $headers[]='Authorization: Bearer '.$this->config['token'];
        $caBundle = $this->localizarCaBundle();
        $opcoes=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if ($caBundle !== null) {
            $opcoes[CURLOPT_CAINFO] = $caBundle;
        } elseif (defined('CURLSSLOPT_NATIVE_CA') && defined('CURLOPT_SSL_OPTIONS')) {
            $opcoes[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        $ch=curl_init($url);
        curl_setopt_array($ch,$opcoes);
        if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE));
        $raw=curl_exec($ch); $errno=curl_errno($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($raw===false||$err!==''){
            if ($errno === CURLE_SSL_CACERT || $errno === CURLE_PEER_FAILED_VERIFICATION) {
                $extra = $caBundle === null ? ' Configure curl.cainfo/openssl.cafile no php.ini do XAMPP para apontar para um CA bundle válido (veja CORRECAO_SSL_VIACEP.md).' : ' O CA bundle configurado não confia na cadeia apresentada pelo servidor/proxy.';
                return ['ok'=>false,'message'=>'Não foi possível validar o certificado HTTPS dos Correios (cURL erro '.$errno.').'.$extra];
            }
            return ['ok'=>false,'message'=>'Falha de comunicação com os Correios: '.$err];
        }
        $data=json_decode($raw,true); if(!is_array($data)) return ['ok'=>false,'message'=>'Resposta inválida dos Correios.'];
        if($status<200||$status>=300) return ['ok'=>false,'message'=>(string)($data['msg']??$data['message']??'A API dos Correios recusou a solicitação.')];
        return ['ok'=>true,'data'=>$data];
    }
}
