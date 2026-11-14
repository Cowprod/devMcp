<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\MacSigningTeams;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class MacSigningTeamsTest extends TestCase
{
    private function certificate(string $team, string $name): array
    {
        $key=openssl_pkey_new(['private_key_bits'=>2048]);
        $csr=openssl_csr_new(['commonName'=>'Apple Development: Test (WRONGCN123)','organizationalUnitName'=>$team,'organizationName'=>$name],$key,['digest_alg'=>'sha256']);
        $cert=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256']);
        openssl_x509_export($cert,$pem);
        return [$pem,openssl_x509_fingerprint($pem,'sha1'),openssl_x509_parse($pem)];
    }
    public function testUsesSubjectOuAndOOnlyWithAUsablePrivateIdentity(): void
    {
        [$pem,$sha,$parsed]=$this->certificate('FB3A74YU84','Listee');
        $identities='  1) '.strtoupper($sha).' "Apple Development: Test (WRONGCN123)"';
        self::assertSame([['id'=>'FB3A74YU84','name'=>'Listee','signing_certificate_count'=>1]],MacSigningTeams::fromKeychain($identities,$pem."\n".$pem));
        self::assertSame([],MacSigningTeams::fromKeychain('', $pem));
        self::assertSame([],MacSigningTeams::fromKeychain($identities,$pem,$parsed['validTo_time_t']));
        self::assertSame([],MacSigningTeams::fromKeychain($identities,$pem,$parsed['validFrom_time_t']-1));
    }
    public function testMultipleTeamsRemainExplicitAndNoSingleTeamIsAutomaticallySelected(): void
    {
        [$a,$sha]=$this->certificate('FB3A74YU84','Listee');
        [$b,$shb]=$this->certificate('7L4C525N8W','Other team');
        $teams=MacSigningTeams::fromKeychain(' 1) '.$sha.' "a"'."\n".' 2) '.$shb.' "b"',$a."\n".$b);
        self::assertCount(2,$teams);
        self::assertSame('FB3A74YU84',MacSigningTeams::requireTeam('FB3A74YU84',$teams));
        try {MacSigningTeams::requireTeam(null,[$teams[1]]);self::fail('Implicit team selection');}
        catch(RuntimeException $e){self::assertSame('APPLE_TEAM_REQUIRED',json_decode($e->getMessage(),true)['code']);}
    }
    public function testRejectsCertificateCnIdentifierWithActionableChoices(): void
    {
        try{MacSigningTeams::requireTeam('23L94Z8RA9',[['id'=>'FB3A74YU84','name'=>'Listee','signing_certificate_count'=>1]]);self::fail('CN identifier accepted');}
        catch(RuntimeException $e){$data=json_decode($e->getMessage(),true,flags:JSON_THROW_ON_ERROR);self::assertSame('APPLE_TEAM_UNAVAILABLE',$data['code']);self::assertSame('FB3A74YU84',$data['available_teams'][0]['id']);}
    }
}
