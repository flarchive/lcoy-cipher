<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher;

use s9e\TextFormatter\Configurator;

class Configure
{
    public function __invoke(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[protected password={TEXT?} title={TEXT?} id={TEXT?} like={TEXT?} reply={TEXT?} follow={TEXT?} followDiscussion={TEXT?} minlikes={TEXT?} time={TEXT?}]{TEXT}[/protected]',
            $this->template()
        );

        // Provide defaults for the renderer parameters used in the template so
        // the JavaScript (composer live preview) renderer never sees an
        // undefined parameter. The PHP render hook overwrites them per request.
        $config->rendering->parameters['CIPHER_LOCKED_TEXT'] = '';
        $config->rendering->parameters['CIPHER_UNLOCK'] = '';

        // At parse time: one-way hash the password attribute and assign a
        // unique id used by the unlock flow.
        $config->tags['PROTECTED']->filterChain
            ->append(ProtectedFilter::class.'::filter')
            ->resetParameters()
            ->addParameterByName('tag');
    }

    protected function template(): string
    {
        // The locked state is decided server-side at render time: RenderContent
        // marks non-allowed viewers' <PROTECTED> nodes with @cipher-locked,
        // embeds every configured visibility requirement (satisfaction status
        // as @data-cipher-req-* and its message as @data-cipher-msg-*) and
        // strips the inner content before the template applies.
        return '<div class="Cipher-box">
	<xsl:choose>
		<xsl:when test="@cipher-locked">
			<div class="Cipher-box-locked">
				<xsl:attribute name="data-cipher-id"><xsl:value-of select="@id"/></xsl:attribute>
				<xsl:if test="@data-post-id">
					<xsl:attribute name="data-post-id"><xsl:value-of select="@data-post-id"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-time">
					<xsl:attribute name="data-cipher-msg-time"><xsl:value-of select="@data-cipher-msg-time"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-time">
					<xsl:attribute name="data-cipher-req-time"><xsl:value-of select="@data-cipher-req-time"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-like">
					<xsl:attribute name="data-cipher-msg-like"><xsl:value-of select="@data-cipher-msg-like"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-like">
					<xsl:attribute name="data-cipher-req-like"><xsl:value-of select="@data-cipher-req-like"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-reply">
					<xsl:attribute name="data-cipher-msg-reply"><xsl:value-of select="@data-cipher-msg-reply"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-reply">
					<xsl:attribute name="data-cipher-req-reply"><xsl:value-of select="@data-cipher-req-reply"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-follow">
					<xsl:attribute name="data-cipher-msg-follow"><xsl:value-of select="@data-cipher-msg-follow"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-follow">
					<xsl:attribute name="data-cipher-req-follow"><xsl:value-of select="@data-cipher-req-follow"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-followDiscussion">
					<xsl:attribute name="data-cipher-msg-followDiscussion"><xsl:value-of select="@data-cipher-msg-followDiscussion"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-followDiscussion">
					<xsl:attribute name="data-cipher-req-followDiscussion"><xsl:value-of select="@data-cipher-req-followDiscussion"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-minlikes">
					<xsl:attribute name="data-cipher-msg-minlikes"><xsl:value-of select="@data-cipher-msg-minlikes"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-req-minlikes">
					<xsl:attribute name="data-cipher-req-minlikes"><xsl:value-of select="@data-cipher-req-minlikes"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@data-cipher-target">
					<xsl:attribute name="data-cipher-target"><xsl:value-of select="@data-cipher-target"/></xsl:attribute>
				</xsl:if>
				<xsl:if test="@title">
					<div class="Cipher-box-title"><xsl:value-of select="@title"/></div>
				</xsl:if>
				<div class="Cipher-box-icon"><i class="fas fa-lock" aria-hidden="true"></i></div>
				<div class="Cipher-box-text"><xsl:value-of select="$CIPHER_LOCKED_TEXT"/></div>
				<div class="Cipher-box-reqs">
				<xsl:if test="@data-cipher-msg-time">
					<div class="Cipher-box-req Cipher-box-req--time"><xsl:choose><xsl:when test="@data-cipher-req-time=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-time"/></span></div>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-like">
					<div class="Cipher-box-req Cipher-box-req--like"><xsl:choose><xsl:when test="@data-cipher-req-like=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-like"/></span></div>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-reply">
					<div class="Cipher-box-req Cipher-box-req--reply"><xsl:choose><xsl:when test="@data-cipher-req-reply=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-reply"/></span></div>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-follow">
					<div class="Cipher-box-req Cipher-box-req--follow"><xsl:choose><xsl:when test="@data-cipher-req-follow=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-follow"/></span></div>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-followDiscussion">
					<div class="Cipher-box-req Cipher-box-req--followDiscussion"><xsl:choose><xsl:when test="@data-cipher-req-followDiscussion=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-followDiscussion"/></span></div>
				</xsl:if>
				<xsl:if test="@data-cipher-msg-minlikes">
					<div class="Cipher-box-req Cipher-box-req--minlikes"><xsl:choose><xsl:when test="@data-cipher-req-minlikes=\'1\'"><i class="fas fa-check Cipher-req-icon Cipher-req-icon--met" aria-hidden="true"></i></xsl:when><xsl:otherwise><i class="fas fa-times Cipher-req-icon Cipher-req-icon--unmet" aria-hidden="true"></i></xsl:otherwise></xsl:choose><span><xsl:value-of select="@data-cipher-msg-minlikes"/></span></div>
				</xsl:if>
			</div>
				<button class="Button Button--primary Cipher-unlock-button" type="button">
					<xsl:value-of select="$CIPHER_UNLOCK"/>
				</button>
			</div>
		</xsl:when>
		<xsl:otherwise>
			<div class="Cipher-box-content"><xsl:apply-templates/></div>
		</xsl:otherwise>
	</xsl:choose>
</div>';
    }
}
